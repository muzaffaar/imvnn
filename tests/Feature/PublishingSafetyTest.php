<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Jobs\Media\SelectMediaForPublishingJob;
use App\Jobs\Telegram\PublishToTelegramJob;
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
        $news = NewsItem::create(['source_id' => $source->id, 'title' => 'News', 'url' => 'https://example.com/story']);
        $channel = TelegramChannel::create(['name' => 'Test', 'chat_id' => '@test', 'is_active' => true]);

        return [$news, new PublishToTelegramJob($channel->id, $news->id, PostMediaType::None, [], 'Caption', null)];
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
}
