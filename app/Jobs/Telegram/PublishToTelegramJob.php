<?php

namespace App\Jobs\Telegram;

use App\Enums\MediaStatus;
use App\Enums\PostMediaType;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\MediaAsset;
use App\Models\MediaProcessingLog;
use App\Models\TelegramChannel;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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

        $result = $this->attempt($publisher, $channel, $assets);

        MediaProcessingLog::record(
            ProcessingStage::Publishing, ProcessingLogStatus::Succeeded,
            newsItemId: $this->newsItemId,
            context: ['strategy' => $result['strategy'], 'telegram_message_id' => $result['message_id'] ?? null],
        );

        foreach ($result['published_asset_ids'] ?? [] as $id) {
            MediaAsset::whereKey($id)->update(['status' => MediaStatus::Published]);
        }
    }

    /** @return array{strategy: string, message_id: mixed, published_asset_ids: list<string>} */
    private function attempt(TelegramPublisher $publisher, TelegramChannel $channel, \Illuminate\Support\Collection $assets): array
    {
        if ($this->type === PostMediaType::MediaGroup && $assets->count() >= 2) {
            try {
                $result = $publisher->sendMediaGroup($channel, $assets->all(), $this->caption);

                return ['strategy' => 'media_group', 'message_id' => $result[0]['message_id'] ?? null, 'published_asset_ids' => $assets->pluck('id')->all()];
            } catch (TelegramApiException $e) {
                $this->logFallback('media_group failed, falling back to single image', $e);
            }
        }

        if ($this->type === PostMediaType::Video && $assets->isNotEmpty()) {
            try {
                $result = $publisher->sendVideo($channel, $assets->first(), $this->caption);

                return ['strategy' => 'video', 'message_id' => $result['message_id'] ?? null, 'published_asset_ids' => [$assets->first()->id]];
            } catch (TelegramApiException $e) {
                $this->logFallback('video send failed, falling back to thumbnail/image', $e);
            }
        }

        // TelegramPostComposer already folds the "watch the video" link into the
        // caption for VideoThumbnailFallback, so no special-casing is needed here.
        foreach ($assets as $asset) {
            try {
                $result = $publisher->sendSinglePhoto($channel, $asset, $this->caption);

                return ['strategy' => 'single_image', 'message_id' => $result['message_id'] ?? null, 'published_asset_ids' => [$asset->id]];
            } catch (TelegramApiException $e) {
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
        Log::warning("[telegram-publish] {$message}: {$e->getMessage()}", ['news_item_id' => $this->newsItemId]);
    }

    public function failed(Throwable $exception): void
    {
        MediaProcessingLog::record(
            ProcessingStage::Publishing, ProcessingLogStatus::Failed,
            newsItemId: $this->newsItemId,
            message: $exception->getMessage(),
            attempt: $this->attempts(),
        );
    }
}
