<?php

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\PostMediaType;
use App\Jobs\Media\SelectMediaForPublishingJob;
use App\Jobs\Telegram\PublishToTelegramJob;
use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Models\Source;
use App\Models\TelegramChannel;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishingSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function publication(): array
    {
        $source = Source::create(['name' => 'Test', 'slug' => 'test', 'base_url' => 'https://example.com', 'type' => 'rss']);
        // Dated today: PublishToTelegramJob re-checks freshness immediately
        // before sending, and ingestion never creates an undated item anyway.
        $news = NewsItem::create(['source_id' => $source->id, 'title' => 'News', 'url' => 'https://example.com/story', 'published_at' => now()]);
        $channel = TelegramChannel::create(['name' => 'Test', 'chat_id' => '@test', 'is_active' => true]);

        return [$news, new PublishToTelegramJob($channel->id, $news->id, PostMediaType::None, [], 'Caption', null)];
    }

    private function publicationDated(?string $publishedAt): array
    {
        [$news, $job] = $this->publication();
        NewsItem::whereKey($news->id)->update(['published_at' => $publishedAt]);

        return [$news->refresh(), $job];
    }

    public function test_an_article_whose_day_has_passed_is_not_sent(): void
    {
        // The scheduler's freshness check does not expire, and minutes or hours
        // can pass before this job runs — a retry backoff, a rate-limit
        // release, a backlog draining past midnight.
        [$news, $job] = $this->publicationDated(now()->subDays(3)->toDateTimeString());
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldNotReceive('sendTextOnly');

        $job->handle($publisher);

        $this->assertNull($news->fresh()->telegram_published_at);
    }

    public function test_a_year_old_article_is_not_sent(): void
    {
        // The Stability AI post: published 10 September 2025, reached the
        // channel on 11 September 2026 because a yearless `<time datetime>`
        // had been parsed as the current year.
        [$news, $job] = $this->publicationDated(now()->subYear()->toDateTimeString());
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldNotReceive('sendTextOnly');

        $job->handle($publisher);

        $this->assertNull($news->fresh()->telegram_published_at);
    }

    public function test_an_undated_article_is_not_sent(): void
    {
        [$news, $job] = $this->publicationDated(null);
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldNotReceive('sendTextOnly');

        $job->handle($publisher);

        $this->assertNull($news->fresh()->telegram_published_at);
    }

    public function test_a_stale_article_releases_its_scheduler_claim(): void
    {
        // A dangling claim would hide from the table that the article is never
        // coming back.
        [$news, $job] = $this->publicationDated(now()->subDays(3)->toDateTimeString());
        NewsItem::whereKey($news->id)->update(['publish_queued_at' => now()]);
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldNotReceive('sendTextOnly');

        $job->handle($publisher);

        $this->assertNull($news->fresh()->publish_queued_at);
    }

    public function test_todays_article_is_still_sent(): void
    {
        [$news, $job] = $this->publicationDated(now()->toDateTimeString());
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldReceive('sendTextOnly')->once()->andReturn(['message_id' => 7]);

        $job->handle($publisher);

        $this->assertNotNull($news->fresh()->telegram_published_at);
    }

    public function test_redelivery_does_not_publish_twice(): void
    {
        [$news, $job] = $this->publication();
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldReceive('sendTextOnly')->once()->andReturn(['message_id' => 42]);
        $job->handle($publisher);
        $job->handle($publisher);
        $this->assertNotNull($news->fresh()->telegram_published_at);
    }

    public function test_inflight_claim_blocks_another_worker(): void
    {
        [$news, $job] = $this->publication();
        NewsItem::whereKey($news->id)->update(['telegram_publish_started_at' => now()]);
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldNotReceive('sendTextOnly');
        $job->handle($publisher);
        $this->assertNull($news->fresh()->telegram_published_at);
    }

    public function test_unknown_outcome_is_held_for_reconciliation(): void
    {
        [$news, $job] = $this->publication();
        $job->withFakeQueueInteractions();
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldReceive('sendTextOnly')->once()->andThrow(new TelegramApiException('Unknown', deliveryUnknown: true));
        $job->handle($publisher);
        $job->assertFailedWith(TelegramApiException::class);
        $this->assertNotNull($news->fresh()->telegram_publish_started_at);
        $job->handle($publisher);
    }

    public function test_rate_limit_releases_claim_and_delays_retry(): void
    {
        [$news, $job] = $this->publication();
        $job->withFakeQueueInteractions();
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldReceive('sendTextOnly')->once()->andThrow(new TelegramApiException('Rate limit', retryAfter: 90));
        $job->handle($publisher);
        $job->assertReleased(90);
        $this->assertNull($news->fresh()->telegram_publish_started_at);
        $this->assertNull($news->fresh()->telegram_published_at);
    }

    public function test_known_undelivered_telegram_failure_releases_claim_for_laravel_retry(): void
    {
        [$news, $job] = $this->publication();
        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldReceive('sendTextOnly')->once()->andThrow(new TelegramApiException('Temporary upstream error'));

        try {
            $job->handle($publisher);
            $this->fail('Expected the worker to receive the delivery error for normal retry handling.');
        } catch (TelegramApiException) {
            $this->assertNull($news->fresh()->telegram_publish_started_at);
            $this->assertNull($news->fresh()->telegram_published_at);
        }
    }

    public function test_failed_media_selection_releases_the_scheduler_claim(): void
    {
        [$news] = $this->publication();
        $news->update(['publish_queued_at' => now()]);

        (new SelectMediaForPublishingJob($news->id, 1))->failed(new \RuntimeException('Selection failed'));

        $this->assertNull($news->fresh()->publish_queued_at);
    }

    public function test_media_group_rejection_falls_back_to_a_single_image_without_retrying_the_job(): void
    {
        [$news] = $this->publication();
        $first = MediaAsset::create([
            'type' => MediaType::Image,
            'status' => MediaStatus::Ready,
            'original_url' => 'https://example.com/first.jpg',
        ]);
        $second = MediaAsset::create([
            'type' => MediaType::Image,
            'status' => MediaStatus::Ready,
            'original_url' => 'https://example.com/second.jpg',
        ]);
        $channel = TelegramChannel::firstOrFail();
        $job = new PublishToTelegramJob($channel->id, $news->id, PostMediaType::MediaGroup, [$first->id, $second->id], 'Caption', null);

        $publisher = $this->mock(TelegramPublisher::class);
        $publisher->shouldReceive('sendMediaGroup')
            ->once()
            ->andThrow(new TelegramApiException('WEBPAGE_CURL_FAILED', mediaRejected: true));
        $publisher->shouldReceive('sendSinglePhoto')
            ->once()
            ->andReturn(['message_id' => 42]);
        $publisher->shouldNotReceive('sendTextOnly');

        $job->handle($publisher);

        $this->assertNotNull($news->fresh()->telegram_published_at);
        $this->assertSame(MediaStatus::Published, $first->fresh()->status);
        $this->assertSame(MediaStatus::Ready, $second->fresh()->status);
    }

    public function test_exhausted_publishing_failure_releases_the_scheduler_and_delivery_claims(): void
    {
        [$news, $job] = $this->publication();
        $news->update([
            'publish_queued_at' => now(),
            'telegram_publish_started_at' => now(),
        ]);

        $job->failed(new \RuntimeException('Final publishing failure'));

        $this->assertNull($news->fresh()->publish_queued_at);
        $this->assertNull($news->fresh()->telegram_publish_started_at);
    }
}
