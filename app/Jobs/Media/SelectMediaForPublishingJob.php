<?php

namespace App\Jobs\Media;

use App\Enums\MediaType;
use App\Enums\MediaVariantType;
use App\Enums\PostMediaType;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Jobs\Telegram\PublishToTelegramJob;
use App\Models\MediaProcessingLog;
use App\Models\NewsItem;
use App\Models\TelegramChannel;
use App\Services\Media\Selection\MediaSelectionService;
use App\Services\Media\Variants\MediaVariantService;
use App\Services\Telegram\CaptionComposerInterface;
use App\Support\Observability\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Bridges "this article is a publication candidate" to "here is exactly what
 * to post". Kept on its own queue (media-selection) separate from
 * telegram-publishing since selection can involve variant generation
 * (image resizing) while publishing is pure HTTP I/O against Telegram.
 */
class SelectMediaForPublishingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $newsItemId,
        public readonly int $telegramChannelId,
    ) {
        $this->onQueue(config('media.queues.selection'));
    }

    public function handle(
        MediaSelectionService $selectionService,
        MediaVariantService $variantService,
        CaptionComposerInterface $composer,
    ): void {
        $newsItem = NewsItem::findOrFail($this->newsItemId);
        $channel = TelegramChannel::findOrFail($this->telegramChannelId);

        $plan = $selectionService->selectForNewsItem($newsItem, $channel);

        foreach ($plan->assets as $asset) {
            if ($asset->type === MediaType::Image || $asset->type === MediaType::Gif) {
                $variantService->ensure($asset, MediaVariantType::Telegram);
            }
        }

        $caption = $composer->compose($newsItem, $plan);

        MediaProcessingLog::record(
            ProcessingStage::Selection, ProcessingLogStatus::Succeeded,
            newsItemId: $newsItem->id,
            context: ['type' => $plan->type->value, 'asset_count' => count($plan->assets)],
        );

        PipelineLogger::info('media.selection_completed', [
            'news_item_id' => $newsItem->id,
            'telegram_channel_id' => $channel->id,
            'post_media_type' => $plan->type->value,
            'asset_count' => count($plan->assets),
        ]);

        if ($plan->type === PostMediaType::None) {
            PublishToTelegramJob::dispatch($channel->id, $newsItem->id, PostMediaType::None, [], $caption, null);

            return;
        }

        PublishToTelegramJob::dispatch(
            $channel->id,
            $newsItem->id,
            $plan->type,
            array_map(fn ($a) => $a->id, $plan->assets),
            $caption,
            $plan->fallbackLinkUrl,
        );
    }

    public function failed(Throwable $exception): void
    {
        $reason = PipelineLogger::exceptionMessage($exception);

        // PublishNextReadyNewsItemJob claims an item before dispatching this
        // job. Once all selection retries are exhausted, release that claim
        // so the scheduler can try again on its normal paced cadence instead
        // of leaving the article permanently stranded at publish_queued_at.
        NewsItem::whereKey($this->newsItemId)
            ->whereNull('telegram_published_at')
            ->update(['publish_queued_at' => null]);

        MediaProcessingLog::record(
            ProcessingStage::Selection, ProcessingLogStatus::Failed,
            newsItemId: $this->newsItemId,
            message: $reason,
        );

        PipelineLogger::exception('media.selection_failed', $exception, [
            'news_item_id' => $this->newsItemId,
            'telegram_channel_id' => $this->telegramChannelId,
        ]);
    }
}
