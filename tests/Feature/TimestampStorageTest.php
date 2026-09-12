<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Jobs\Telegram\PublishToTelegramJob;
use App\Models\NewsItem;
use App\Models\Source;
use App\Models\TelegramChannel;
use App\Services\News\FreshnessPolicy;
use App\Services\Telegram\TelegramPublisher;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a `timestamp without time zone` column actually means.
 *
 * The columns carry a bare reading and no offset, so `2026-09-11 23:24:38` is
 * not an instant until something names the zone it was written in. Laravel's
 * built-in `datetime` cast names PHP's default zone — `app.timezone` — which
 * is right only while the application happens to print in the same zone the
 * database happens to hold. Nothing enforces that, and when it broke the
 * failure was silent and exactly one offset wide: on 12 September 2026 the
 * freshness window was computed in UTC against Asia/Tashkent readings, the
 * scheduler queued articles from yesterday evening, and the send-time re-check
 * threw every one of them away as `not_fresh_at_send_time`.
 *
 * So these tests pin the raw column reading on one side and the instant the
 * application derives from it on the other, under both storage zones — and
 * pin that the SQL window and the in-process check never disagree about which
 * articles those are.
 */
class TimestampStorageTest extends TestCase
{
    use RefreshDatabase;

    /** The reading the outage was reported against. */
    private const READING = '2026-09-11 23:24:38';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Tashkent',
            'news_sources.freshness.only_today' => true,
            'news_sources.freshness.max_age_hours' => null,
            'news_sources.freshness.timezone' => 'Asia/Tashkent',
        ]);

        // Mid-morning in Tashkent on the day the two zones disagreed.
        Carbon::setTestNow(Carbon::parse('2026-09-12 09:00:00', 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function articleStoredAs(?string $reading): NewsItem
    {
        $source = Source::firstOrCreate(
            ['slug' => 'test'],
            ['name' => 'Test', 'base_url' => 'https://example.com', 'type' => 'rss'],
        );

        $news = NewsItem::create([
            'source_id' => $source->id,
            'title' => 'Story',
            'url' => 'https://example.com/'.Str::uuid(),
        ]);

        // Written past the model on purpose: the point of these tests is what
        // the column literally holds, not what a cast would have put there.
        DB::table('news_items')->where('id', $news->id)->update(['published_at' => $reading]);

        return NewsItem::findOrFail($news->id);
    }

    private function rawPublishedAt(NewsItem $news): ?string
    {
        return DB::table('news_items')->where('id', $news->id)->value('published_at');
    }

    public function test_a_stored_reading_is_hydrated_as_an_instant_in_the_storage_timezone(): void
    {
        config(['app.storage_timezone' => 'UTC']);

        $news = $this->articleStoredAs(self::READING);

        $this->assertSame('2026-09-11T23:24:38+00:00', $news->published_at->toIso8601String());
    }

    public function test_the_same_reading_is_a_different_instant_under_a_different_storage_timezone(): void
    {
        // Same five digits in the column, five hours apart in meaning. This is
        // why the zone has to be stated somewhere rather than inferred.
        config(['app.storage_timezone' => 'Asia/Tashkent']);

        $news = $this->articleStoredAs(self::READING);

        $this->assertSame('2026-09-11T23:24:38+05:00', $news->published_at->toIso8601String());
    }

    public function test_hydration_does_not_follow_the_php_default_timezone(): void
    {
        // The old cast read the column in whatever zone PHP was set to, which
        // is how `app.timezone` silently became load-bearing for stored data.
        config(['app.storage_timezone' => 'UTC']);
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');
            $news = $this->articleStoredAs(self::READING);

            $this->assertSame('2026-09-11T23:24:38+00:00', $news->published_at->toIso8601String());
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_a_date_is_written_back_as_the_storage_timezone_wall_clock(): void
    {
        config(['app.storage_timezone' => 'UTC']);
        $news = $this->articleStoredAs(null);

        // 04:24 in Tashkent is 23:24 the previous day in UTC. A write that
        // formatted the value as-is would put the Tashkent reading in a UTC
        // column, which is the same bug running in the other direction.
        $news->published_at = CarbonImmutable::parse('2026-09-12 04:24:38', 'Asia/Tashkent');
        $news->save();

        $this->assertSame(self::READING, $this->rawPublishedAt($news));
        $this->assertSame('2026-09-11T23:24:38+00:00', $news->fresh()->published_at->toIso8601String());
    }

    public function test_a_write_and_a_read_round_trip_to_the_same_instant(): void
    {
        foreach (['UTC', 'Asia/Tashkent'] as $zone) {
            config(['app.storage_timezone' => $zone]);
            $moment = CarbonImmutable::parse('2026-09-12 04:24:38', 'Asia/Tashkent');

            $news = $this->articleStoredAs(null);
            $news->published_at = $moment;
            $news->save();

            $this->assertTrue(
                $moment->equalTo($news->fresh()->published_at),
                "Round trip lost the instant with storage timezone {$zone}",
            );
        }
    }

    public function test_an_article_from_early_in_the_tashkent_day_is_fresh_when_storage_is_utc(): void
    {
        // 23:24 UTC on the 11th is 04:24 on the 12th in Tashkent — today's
        // news, and the article the send-time check was rejecting.
        config(['app.storage_timezone' => 'UTC']);

        $news = $this->articleStoredAs(self::READING);

        $this->assertTrue(app(FreshnessPolicy::class)->isFresh($news->published_at));
    }

    public function test_an_article_from_early_in_the_tashkent_day_is_fresh_when_storage_is_the_application_timezone(): void
    {
        // The same article, in a database that stores Tashkent readings.
        config(['app.storage_timezone' => 'Asia/Tashkent']);

        $news = $this->articleStoredAs('2026-09-12 04:24:38');

        $this->assertTrue(app(FreshnessPolicy::class)->isFresh($news->published_at));
    }

    public function test_yesterday_evening_is_not_fresh_when_storage_is_the_application_timezone(): void
    {
        // Same reading as the UTC case above, and under this storage zone it
        // genuinely is yesterday. Freshness follows the data, not the digits.
        config(['app.storage_timezone' => 'Asia/Tashkent']);

        $news = $this->articleStoredAs(self::READING);

        $this->assertFalse(app(FreshnessPolicy::class)->isFresh($news->published_at));
    }

    public function test_the_scheduler_window_selects_exactly_what_the_send_time_check_accepts(): void
    {
        // The regression itself. The scheduler filters in SQL against stored
        // readings while PublishToTelegramJob re-checks in PHP against
        // hydrated instants; when those two disagreed, the scheduler queued
        // articles the send-time check then refused to publish.
        foreach (['UTC', 'Asia/Tashkent'] as $zone) {
            config(['app.storage_timezone' => $zone]);
            $policy = new FreshnessPolicy;

            $articles = collect([
                '2026-09-11 18:00:00',
                '2026-09-11 23:24:38',
                '2026-09-12 04:24:38',
                '2026-09-12 08:30:00',
            ])->map(fn (string $reading) => $this->articleStoredAs($reading));

            $selected = NewsItem::query()
                ->where('published_at', '>=', $policy->windowStart())
                ->where('published_at', '<', $policy->windowEnd())
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $accepted = $articles
                ->filter(fn (NewsItem $news) => $policy->isFresh($news->published_at))
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $this->assertSame($accepted, $selected, "SQL and PHP disagreed with storage timezone {$zone}");
            $this->assertNotEmpty($accepted, "Nothing was fresh at all with storage timezone {$zone}");

            NewsItem::query()->delete();
        }
    }

    public function test_a_valid_todays_article_is_published_rather_than_rejected_at_send_time(): void
    {
        // End to end on the reported failure: the job's last gate must let
        // today's article through instead of logging not_fresh_at_send_time.
        config(['app.storage_timezone' => 'UTC']);

        $news = $this->articleStoredAs(self::READING);
        $channel = TelegramChannel::create(['name' => 'Test', 'chat_id' => '@test', 'is_active' => true]);

        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldReceive('sendTextOnly')->once()->andReturn(['message_id' => 42]);

        (new PublishToTelegramJob($channel->id, $news->id, PostMediaType::None, [], 'Caption', null))
            ->handle($publisher);

        $this->assertNotNull($news->fresh()->telegram_published_at);
    }
}
