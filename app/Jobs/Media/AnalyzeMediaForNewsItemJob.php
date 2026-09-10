<?php

namespace App\Jobs\Media;

use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\MediaProcessingLog;
use App\Models\NewsItem;
use App\Services\Media\EventMediaPoolService;
use App\Services\Media\Scoring\MediaQualityScorer;
use App\Services\Media\Scoring\RelevanceAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs once every download/video job for a news item's media has finished
 * (dispatched by MediaPipelineProgressTracker once ExtractMediaJob's fan-out
 * for this article reaches zero outstanding jobs). Finalizes relevance
 * (including the selective vision-model pass) and caches a quality score per
 * asset, then contributes usable assets to the parent Event's shared media
 * pool if this article belongs to one.
 */
class AnalyzeMediaForNewsItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public readonly string $newsItemId)
    {
        $this->onQueue(config('media.queues.image_analysis'));
    }

    public function handle(
        RelevanceAnalysisService $relevanceAnalysis,
        MediaQualityScorer $qualityScorer,
        EventMediaPoolService $eventMediaPool,
    ): void {
        $newsItem = NewsItem::with(['mediaAssets' => fn ($q) => $q->with('source', 'duplicates')])->findOrFail($this->newsItemId);

        $relevanceAnalysis->analyzeForNewsItem($newsItem);

        foreach ($newsItem->mediaAssets as $asset) {
            if (! $asset->isUsable()) {
                continue;
            }

            $score = $qualityScorer->score($asset);
            if ($score !== $asset->quality_score) {
                $asset->update(['quality_score' => $score]);
            }
        }

        $eventMediaPool->syncFromNewsItem($newsItem->fresh('mediaAssets'));

        // Marks this item as a publishing candidate — see
        // PublishNextReadyNewsItemJob, which only ever selects from items
        // where this is set.
        $newsItem->update(['media_analysis_completed_at' => now()]);

        MediaProcessingLog::record(
            ProcessingStage::QualityAnalysis, ProcessingLogStatus::Succeeded,
            newsItemId: $newsItem->id,
        );
    }

    public function failed(Throwable $exception): void
    {
        MediaProcessingLog::record(
            ProcessingStage::QualityAnalysis, ProcessingLogStatus::Failed,
            newsItemId: $this->newsItemId,
            message: $exception->getMessage(),
        );
    }
}
