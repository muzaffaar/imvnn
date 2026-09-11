<?php

namespace Tests\Feature;

use App\Jobs\Telegram\PublishNextReadyNewsItemJob;
use App\Models\TelegramChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cadence under the "only today's news" policy, where an article that misses
 * midnight is abandoned rather than carried over.
 *
 * A fixed interval silently discards whatever the day could not fit: ten
 * articles at five minutes each needs fifty minutes, so a chain that reaches
 * 23:30 with ten waiting loses four of them and the channel merely looks like it
 * had less news. Pacing to the deadline makes it speed up *because* the day is
 * ending.
 *
 * Assertions are on the *ceiling* wherever that differs from the returned delay,
 * because the delay is deliberately randomised within [floor, ceiling] so the
 * channel never posts on a robotic beat. Comparing two random draws would pass
 * or fail by luck.
 */
class PublishPacingTest extends TestCase
{
    use RefreshDatabase;

    private const FLOOR_MINUTES = 5;

    private const BASELINE_MINUTES = 60;

    protected function setUp(): void
    {
        parent::setUp();

        // The audience timezone must match the one timestamps are stored in, as
        // it does in the real configuration. Forcing either to UTC here made
        // "end of day" mean two different things: config() does not re-apply
        // PHP's default timezone, so now() stayed in the booted zone while the
        // window moved, and the tests measured a five-hour offset rather than the
        // deadline they meant to.
        config([
            'news_sources.freshness.only_today' => true,
            'news_sources.freshness.max_age_hours' => null,
            'news_sources.freshness.timezone' => config('app.timezone'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function channel(array $rules = []): TelegramChannel
    {
        return TelegramChannel::create([
            'name' => 'Test',
            'chat_id' => '@test'.TelegramChannel::count(),
            'is_active' => true,
            'rules' => TelegramChannel::defaultRules($rules + [
                'min_publish_interval_minutes' => self::FLOOR_MINUTES,
                'max_publish_interval_minutes' => self::BASELINE_MINUTES,
            ]),
        ]);
    }

    private function invoke(TelegramChannel $channel, string $method, array $args): mixed
    {
        $job = new PublishNextReadyNewsItemJob($channel->id, 'token');
        $reflected = new ReflectionMethod($job, $method);
        $reflected->setAccessible(true);

        return $reflected->invoke($job, ...$args);
    }

    /** The paced ceiling in seconds, or null when there is no shared deadline. */
    private function ceiling(TelegramChannel $channel, int $backlog): ?int
    {
        return $this->invoke($channel, 'deadlineCeilingSeconds', [$backlog, $channel]);
    }

    private function delay(TelegramChannel $channel, int $backlog): int
    {
        return $this->invoke($channel, 'computeDelaySeconds', [
            self::FLOOR_MINUTES, self::BASELINE_MINUTES, $backlog, $channel,
        ]);
    }

    private function atHour(int $hour, int $minute = 0): void
    {
        $this->travelTo(now()->startOfDay()->addHours($hour)->addMinutes($minute));
    }

    public function test_an_empty_backlog_waits_the_full_baseline(): void
    {
        $this->assertSame(self::BASELINE_MINUTES * 60, $this->delay($this->channel(), 0));
    }

    public function test_the_ceiling_tightens_as_the_day_runs_out(): void
    {
        $channel = $this->channel();

        $this->atHour(6);
        $early = $this->ceiling($channel, 6);

        $this->atHour(22);
        $late = $this->ceiling($channel, 6);

        $this->assertNotNull($early);
        $this->assertNotNull($late);
        $this->assertLessThan($early, $late);
    }

    public function test_the_ceiling_tightens_as_the_backlog_grows(): void
    {
        $channel = $this->channel();
        $this->atHour(12);

        $this->assertLessThan($this->ceiling($channel, 2), $this->ceiling($channel, 40));
    }

    public function test_the_whole_backlog_fits_before_the_window_closes(): void
    {
        $channel = $this->channel();
        $this->atHour(22); // two hours left
        $backlog = 8;

        $ceiling = $this->ceiling($channel, $backlog);

        // Every remaining article has to fit, with room to spare rather than the
        // last one landing on the stroke of midnight.
        $this->assertLessThan(2 * 3600, $ceiling * $backlog);
    }

    public function test_the_floor_is_never_breached_however_tight_the_deadline(): void
    {
        $channel = $this->channel();
        $this->atHour(23, 58); // two minutes left, fifty waiting

        // Most of them will expire. The channel still must not flood the feed.
        $this->assertSame(self::FLOOR_MINUTES * 60, $this->delay($channel, 50));
    }

    public function test_the_baseline_is_never_exceeded(): void
    {
        $channel = $this->channel();
        $this->atHour(0); // a whole day ahead

        // One article with 24 hours left would pace to 12 hours on its own.
        $this->assertLessThanOrEqual(self::BASELINE_MINUTES * 60, $this->delay($channel, 1));
    }

    public function test_deadline_pacing_can_be_turned_off_per_channel(): void
    {
        $this->atHour(23);

        $this->assertNotNull($this->ceiling($this->channel(), 3));
        $this->assertNull($this->ceiling($this->channel(['pace_to_end_of_day' => false]), 3));
    }

    public function test_the_rolling_lookback_policy_has_no_deadline_to_pace_against(): void
    {
        // Each article expires on its own clock rather than all of them at
        // midnight, so there is no shared deadline and the pre-existing
        // backlog-saturation rule governs instead.
        config(['news_sources.freshness.max_age_hours' => 72]);
        $channel = $this->channel();
        $this->atHour(23, 59);

        $this->assertNull($this->ceiling($channel, 10));
        $this->assertSame(self::FLOOR_MINUTES * 60, $this->delay($channel, 10));
    }

    public function test_freshness_switched_off_entirely_has_no_deadline_either(): void
    {
        config(['news_sources.freshness.only_today' => false]);
        $this->atHour(23, 59);

        $this->assertNull($this->ceiling($this->channel(), 10));
    }

    public function test_a_window_that_has_already_closed_does_not_collapse_the_delay(): void
    {
        // Defensive: a zero or negative remainder must not yield a zero delay and
        // a burst of posts.
        $channel = $this->channel();
        $this->travelTo(now()->startOfDay()->addDay());

        $this->assertGreaterThanOrEqual(self::FLOOR_MINUTES * 60, $this->delay($channel, 10));
    }
}
