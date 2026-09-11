<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Jobs\Telegram\PublishToTelegramJob;
use App\Models\NewsItem;
use App\Models\PublishedPost;
use App\Models\Source;
use App\Models\TelegramChannel;
use App\Services\Telegram\TelegramPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `news_items.telegram_published_at` only answers "has *this row* been sent", so
 * it dies with the row. Re-syncing sources, clearing stale articles or
 * re-ingesting after a reset all produce fresh rows with an empty publish
 * history — and three articles already in the channel were sent a second time
 * that way. `published_posts` is keyed on the canonical URL instead, which is
 * what identifies an article across ingestions.
 */
class AlreadySentGuardTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://example.com/blog/a-story';

    private const CANONICAL = 'example.com/blog/a-story';

    private function channel(): TelegramChannel
    {
        return TelegramChannel::create(['name' => 'Test', 'chat_id' => '@test', 'is_active' => true]);
    }

    private function article(): NewsItem
    {
        // firstOrCreate, because a test that rebuilds the articles table calls
        // this twice and the source outlives them.
        $source = Source::firstOrCreate(
            ['slug' => 'test'],
            ['name' => 'Test', 'base_url' => 'https://example.com', 'type' => 'rss'],
        );

        return NewsItem::create([
            'source_id' => $source->id,
            'title' => 'A story',
            'url' => self::URL,
            'canonical_url' => self::CANONICAL,
            'published_at' => now(),
            'media_analysis_completed_at' => now(),
        ]);
    }

    private function job(TelegramChannel $channel, NewsItem $article): PublishToTelegramJob
    {
        return new PublishToTelegramJob($channel->id, $article->id, PostMediaType::None, [], 'Caption', null);
    }

    public function test_a_successful_send_is_recorded_in_the_ledger(): void
    {
        $channel = $this->channel();
        $article = $this->article();
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldReceive('sendTextOnly')->once()->andReturn(['message_id' => 42]);

        $this->job($channel, $article)->handle($publisher);

        $post = PublishedPost::sole();
        $this->assertSame(self::CANONICAL, $post->canonical_url);
        $this->assertSame(42, $post->telegram_message_id);
        $this->assertSame($channel->id, $post->telegram_channel_id);
    }

    public function test_a_re_ingested_article_is_not_sent_again(): void
    {
        $channel = $this->channel();
        $first = $this->article();
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldReceive('sendTextOnly')->once()->andReturn(['message_id' => 42]);
        $this->job($channel, $first)->handle($publisher);

        // The articles table is rebuilt: same story, brand new row, no history.
        NewsItem::query()->delete();
        $second = $this->article();
        $this->assertNull($second->telegram_published_at);

        $this->job($channel, $second)->handle($publisher); // shouldReceive once, so a second send fails the test

        $this->assertSame(1, PublishedPost::count());
    }

    public function test_a_re_ingested_article_is_marked_sent_so_it_stops_being_reconsidered(): void
    {
        $channel = $this->channel();
        PublishedPost::record($channel->id, self::CANONICAL, title: 'A story');
        $article = $this->article();
        NewsItem::whereKey($article->id)->update(['publish_queued_at' => now()]);
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldNotReceive('sendTextOnly');

        $this->job($channel, $article)->handle($publisher);

        $fresh = $article->fresh();
        $this->assertNotNull($fresh->telegram_published_at);
        $this->assertNull($fresh->publish_queued_at);
    }

    public function test_the_ledger_is_per_channel(): void
    {
        $first = $this->channel();
        $second = TelegramChannel::create(['name' => 'Second', 'chat_id' => '@second', 'is_active' => true]);
        PublishedPost::record($first->id, self::CANONICAL);

        $this->assertTrue(PublishedPost::alreadySent($first->id, self::CANONICAL));
        $this->assertFalse(PublishedPost::alreadySent($second->id, self::CANONICAL));
    }

    public function test_recording_the_same_article_twice_is_harmless(): void
    {
        // Called straight after Telegram accepts a post, so throwing here would
        // push the job into a retry that could publish the article again.
        $channel = $this->channel();
        PublishedPost::record($channel->id, self::CANONICAL);
        PublishedPost::record($channel->id, self::CANONICAL);

        $this->assertSame(1, PublishedPost::count());
    }

    public function test_an_article_with_no_canonical_url_is_never_treated_as_sent(): void
    {
        $channel = $this->channel();

        $this->assertFalse(PublishedPost::alreadySent($channel->id, null));
        $this->assertFalse(PublishedPost::alreadySent($channel->id, ''));
    }

    public function test_the_scheduler_will_not_reselect_an_article_already_sent(): void
    {
        $channel = $this->channel();
        $article = $this->article();
        PublishedPost::record($channel->id, self::CANONICAL);

        // Reaches the scheduler's eligibility query through its candidate pick.
        $job = new \App\Jobs\Telegram\PublishNextReadyNewsItemJob($channel->id, 'token');
        $eligible = (new \ReflectionMethod($job, 'eligibleQuery'));
        $eligible->setAccessible(true);

        $this->assertSame(0, $eligible->invoke($job)->count());

        PublishedPost::query()->delete();
        $this->assertSame(1, $eligible->invoke($job)->count());
        $this->assertSame($article->id, $eligible->invoke($job)->first()->id);
    }
}
