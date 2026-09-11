<?php

namespace App\Jobs\Telegram;

use App\Enums\MediaStatus;
use App\Enums\PostMediaType;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\MediaAsset;
use App\Models\MediaProcessingLog;
use App\Models\NewsItem;
use App\Models\TelegramChannel;
use App\Services\News\FreshnessPolicy;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramPublisher;
use App\Support\Observability\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Publishes one post, falling back down the ranked asset list — and
 * eventually to text-only — rather than letting one bad asset kill the
 * whole post. See docs/MEDIA_ARCHITECTURE.md "Publishing fallback ladder".
 *
 *   media group  -> single image (best remaining) -> ... -> text only
 *   video        -> video-thumbnail fallback       -> ... -> text only
 *   single image -> next remaining image           -> ... -> text only
 */
class PublishToTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [15, 60, 300, 900];

    /** @param list<string> $mediaAssetIds ranked best-first */
    public function __construct(
        public readonly int $telegramChannelId,
        public readonly string $newsItemId,
        public readonly PostMediaType $type,
        public readonly array $mediaAssetIds,
        public readonly string $caption,
        public readonly ?string $fallbackLinkUrl,
    ) {
        $this->onQueue(config('media.queues.publishing'));
    }

    public function handle(TelegramPublisher $publisher): void
    {
        $channel = TelegramChannel::findOrFail($this->telegramChannelId);
        $assets = empty($this->mediaAssetIds)
            ? collect()
            : MediaAsset::whereIn('id', $this->mediaAssetIds)->get()->sortBy(
                fn (MediaAsset $a) => array_search($a->id, $this->mediaAssetIds)
            )->values();

        if (! $channel->is_active) {
            PipelineLogger::warning('telegram.publish_skipped', [
                'telegram_channel_id' => $channel->id,
                'news_item_id' => $this->newsItemId,
                'reason' => 'channel_inactive',
            ]);

            return;
        }

        // Last gate before the send, and the only one that sees the clock at
        // the moment of publication. The scheduler already filters on
        // freshness, but minutes or hours can pass between its check and this
        // job — a retry backoff, a rate-limit release, a backlog drained after
        // midnight — and the scheduler's verdict does not expire. A year-old
        // Stability AI article reaching the channel is what makes a second
        // query worth it: one stale post does more damage than one missed post.
        $newsItem = NewsItem::find($this->newsItemId);

        if (! $newsItem || ! app(FreshnessPolicy::class)->isFresh($newsItem->published_at)) {
            // Release the scheduler's claim rather than holding it: the article
            // is not coming back, and a dangling claim hides that from anyone
            // reading the table.
            NewsItem::whereKey($this->newsItemId)
                ->whereNull('telegram_published_at')
                ->update(['publish_queued_at' => null]);

            PipelineLogger::warning('telegram.publish_skipped', [
                'telegram_channel_id' => $channel->id,
                'news_item_id' => $this->newsItemId,
                'reason' => $newsItem ? 'not_fresh_at_send_time' : 'news_item_missing',
                'published_at' => $newsItem?->published_at?->toIso8601String(),
            ]);

            return;
        }

        // Durable claim: queue redelivery and concurrent workers must not send
        // again, including after a crash between Telegram acceptance and save.
        if (! NewsItem::whereKey($this->newsItemId)
            ->whereNull('telegram_published_at')
            ->whereNull('telegram_publish_started_at')
            ->update(['telegram_publish_started_at' => now()])) {
            PipelineLogger::info('telegram.publish_skipped', [
                'telegram_channel_id' => $channel->id,
                'news_item_id' => $this->newsItemId,
                'reason' => 'already_claimed_or_published',
            ]);

            return;
        }

        PipelineLogger::info('telegram.publish_started', [
            'telegram_channel_id' => $channel->id,
            'news_item_id' => $this->newsItemId,
            'requested_media_type' => $this->type->value,
            'asset_count' => $assets->count(),
        ]);

        try {
            $result = $this->attempt($publisher, $channel, $assets);
        } catch (TelegramApiException $e) {
            if ($e->deliveryUnknown) {
                PipelineLogger::exception('telegram.publish_delivery_unknown', $e, [
                    'telegram_channel_id' => $channel->id,
                    'news_item_id' => $this->newsItemId,
                ]);
                $this->fail($e);

                return;
            }

            $this->releasePublishClaim();
            if ($e->retryAfter !== null) {
                PipelineLogger::exception('telegram.publish_rate_limited', $e, [
                    'telegram_channel_id' => $channel->id,
                    'news_item_id' => $this->newsItemId,
                    'retry_after_seconds' => $e->retryAfter,
                ], 'warning');
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        } catch (Throwable $e) {
            // This is known not to have reached Telegram. Clear the durable
            // claim before Laravel retries, otherwise the next attempt sees
            // telegram_publish_started_at and exits as if another worker had
            // already delivered the post.
            $this->releasePublishClaim();

            throw $e;
        }

        NewsItem::whereKey($this->newsItemId)->update(['telegram_published_at' => now()]);

        MediaProcessingLog::record(
            ProcessingStage::Publishing, ProcessingLogStatus::Succeeded,
            newsItemId: $this->newsItemId,
            context: ['strategy' => $result['strategy'], 'telegram_message_id' => $result['message_id'] ?? null],
        );

        foreach ($result['published_asset_ids'] ?? [] as $id) {
            MediaAsset::whereKey($id)->update(['status' => MediaStatus::Published]);
        }

        PipelineLogger::info('telegram.publish_completed', [
            'telegram_channel_id' => $channel->id,
            'news_item_id' => $this->newsItemId,
            'strategy' => $result['strategy'],
            'telegram_message_id' => $result['message_id'] ?? null,
            'published_asset_count' => count($result['published_asset_ids'] ?? []),
        ]);

    }

    /** @return array{strategy: string, message_id: mixed, published_asset_ids: list<string>} */
    private function attempt(TelegramPublisher $publisher, TelegramChannel $channel, Collection $assets): array
    {
        if ($this->type === PostMediaType::MediaGroup && $assets->count() >= 2) {
            try {
                $result = $publisher->sendMediaGroup($channel, $assets->all(), $this->caption);

                return ['strategy' => 'media_group', 'message_id' => $result[0]['message_id'] ?? null, 'published_asset_ids' => $assets->pluck('id')->all()];
            } catch (TelegramApiException $e) {
                if (! $e->mediaRejected) {
                    throw $e;
                }
                $this->logFallback('media_group failed, falling back to single image', $e);
            }
        }

        if ($this->type === PostMediaType::Video && $assets->isNotEmpty()) {
            try {
                $result = $publisher->sendVideo($channel, $assets->first(), $this->caption);

                return ['strategy' => 'video', 'message_id' => $result['message_id'] ?? null, 'published_asset_ids' => [$assets->first()->id]];
            } catch (TelegramApiException $e) {
                if (! $e->mediaRejected) {
                    throw $e;
                }
                $this->logFallback('video send failed, falling back to thumbnail/image', $e);
            }
        }

        // The caption composer (see App\Services\Telegram\CaptionComposerInterface)
        // already handles mentioning a VideoThumbnailFallback's video in the
        // text itself (no link), so no special-casing is needed here.
        foreach ($assets as $asset) {
            if (! $asset->type->isVisual()) {
                continue;
            }
            try {
                $result = $publisher->sendSinglePhoto($channel, $asset, $this->caption);

                return ['strategy' => 'single_image', 'message_id' => $result['message_id'] ?? null, 'published_asset_ids' => [$asset->id]];
            } catch (TelegramApiException $e) {
                if (! $e->mediaRejected) {
                    throw $e;
                }
                $this->logFallback("single image {$asset->id} failed, trying next candidate", $e);

                continue;
            }
        }

        // Every media asset failed (or there was never any media to begin with).
        $result = $publisher->sendTextOnly($channel, $this->caption);

        return ['strategy' => 'text_only', 'message_id' => $result['message_id'] ?? null, 'published_asset_ids' => []];
    }

    private function logFallback(string $message, Throwable $e): void
    {
        PipelineLogger::exception('telegram.publish_media_fallback', $e, [
            'news_item_id' => $this->newsItemId,
            'fallback_reason' => $message,
        ], 'warning');
    }

    private function releasePublishClaim(): void
    {
        NewsItem::whereKey($this->newsItemId)
            ->whereNull('telegram_published_at')
            ->update(['telegram_publish_started_at' => null]);
    }

    public function failed(Throwable $exception): void
    {
        $reason = PipelineLogger::exceptionMessage($exception);

        // A terminal publishing failure must not leave the scheduler's claim
        // behind forever. The next paced scheduler pass may select it again.
        NewsItem::whereKey($this->newsItemId)
            ->whereNull('telegram_published_at')
            ->update([
                'publish_queued_at' => null,
                'telegram_publish_started_at' => null,
            ]);

        MediaProcessingLog::record(
            ProcessingStage::Publishing, ProcessingLogStatus::Failed,
            newsItemId: $this->newsItemId,
            message: $reason,
            attempt: $this->attempts(),
        );

        PipelineLogger::exception('telegram.publish_failed', $exception, [
            'telegram_channel_id' => $this->telegramChannelId,
            'news_item_id' => $this->newsItemId,
            'attempt' => $this->attempts(),
        ]);
    }
}
