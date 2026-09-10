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
use App\Services\Telegram\TelegramPostComposer;
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
        TelegramPostComposer $composer,
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
        MediaProcessingLog::record(
            ProcessingStage::Selection, ProcessingLogStatus::Failed,
            newsItemId: $this->newsItemId,
            message: $exception->getMessage(),
        );
    }
}
