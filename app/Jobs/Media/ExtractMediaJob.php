<?php

namespace App\Jobs\Media;

use App\DTOs\ExtractionContext;
use App\Enums\MediaIngestionAction;
use App\Enums\MediaProvider;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\MediaAsset;
use App\Models\MediaProcessingLog;
use App\Models\NewsItem;
use App\Services\Media\Deduplication\MediaDuplicateDetectionService;
use App\Services\Media\Extraction\MediaExtractionManager;
use App\Services\Media\MediaIngestionPolicy;
use App\Services\Media\MediaPipelineProgressTracker;
use App\Services\Media\Scoring\MediaRelevanceScorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entry point of the media pipeline: extraction -> ingestion decision ->
 * duplicate check -> asset creation -> attach to the article -> fan out
 * download/video jobs, then (once every one of those finishes) hand off to
 * relevance/quality analysis. This must never block article ingestion itself
 * — it is dispatched, not called synchronously, from wherever the news
 * pipeline finishes parsing an article.
 */
class ExtractMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $newsItemId)
    {
        $this->onQueue(config('media.queues.extraction'));
    }

    public function handle(
        MediaExtractionManager $extractionManager,
        MediaIngestionPolicy $policy,
        MediaDuplicateDetectionService $duplicateDetection,
        MediaRelevanceScorer $relevanceScorer,
    ): void {
        $newsItem = NewsItem::with('source')->findOrFail($this->newsItemId);

        $context = new ExtractionContext(
            newsItemId: $newsItem->id,
            baseUrl: $newsItem->canonical_url ?: $newsItem->url,
            title: $newsItem->title,
            html: $newsItem->raw_html,
            rssItem: data_get($newsItem->source_payload, 'rss_item'),
        );

        $candidates = $extractionManager->extract($context);

        MediaProcessingLog::record(
            ProcessingStage::Extraction, ProcessingLogStatus::Succeeded,
            newsItemId: $newsItem->id,
            message: "{$candidates->count()} candidate(s) found",
        );

        $followUpJobs = [];
        $total = $candidates->count();

        foreach ($candidates as $extracted) {
            $canonicalUrl = $duplicateDetection->normalizeUrl($extracted->url);

            if ($existingMatch = $duplicateDetection->findByUrl($extracted->url, $extracted->type)) {
                $this->attachToArticle($newsItem, $existingMatch->canonical, $extracted, $relevanceScorer, $total);

                continue;
            }

            $action = $policy->decide($extracted, $newsItem->source);

            if ($action === MediaIngestionAction::Ignore) {
                continue;
            }

            $asset = MediaAsset::create([
                'type' => $extracted->type,
                'status' => MediaStatus::Pending,
                'provider' => $extracted->type === MediaType::Embed ? MediaProvider::Embed : MediaProvider::External,
                'original_url' => $extracted->url,
                'canonical_url' => $canonicalUrl,
                'source_id' => $newsItem->source_id,
                'external_provider' => $extracted->externalProvider,
                'external_id' => $extracted->externalId,
                'width' => $extracted->width,
                'height' => $extracted->height,
                'duration_seconds' => $extracted->durationSeconds,
                'caption' => $extracted->caption,
                'alt_text' => $extracted->altText,
                'metadata' => array_filter(['thumbnail_url' => $extracted->thumbnailUrl]),
            ]);

            $this->attachToArticle($newsItem, $asset, $extracted, $relevanceScorer, $total);

            if ($action === MediaIngestionAction::Download) {
                $followUpJobs[] = new DownloadMediaJob($asset->id, $newsItem->id);
            } elseif ($extracted->type === MediaType::Video || $extracted->type === MediaType::Audio) {
                $followUpJobs[] = new ProcessVideoJob($asset->id, $newsItem->id);
            } else {
                // Reference-only image/embed: nothing to fetch, immediately usable.
                $asset->update(['status' => MediaStatus::Ready, 'processed_at' => now()]);
            }
        }

        $this->dispatchFollowUp($newsItem->id, $followUpJobs);
    }

    private function attachToArticle(NewsItem $newsItem, MediaAsset $asset, $extracted, MediaRelevanceScorer $scorer, int $total): void
    {
        $relevance = $scorer->scoreExtracted($extracted, $newsItem->title, $total);

        $newsItem->mediaAssets()->syncWithoutDetaching([
            $asset->id => [
                'id' => (string) Str::uuid(),
                'role' => $extracted->isFeaturedHint ? 'featured' : 'body',
                'is_featured' => $extracted->isFeaturedHint,
                'position' => $extracted->position,
                'caption_override' => $extracted->caption,
                'relevance_score' => $relevance,
            ],
        ]);

        if ($relevance > ($asset->relevance_score ?? 0)) {
            $asset->update(['relevance_score' => $relevance]);
        }
    }

    /** @param list<ShouldQueue> $jobs */
    private function dispatchFollowUp(string $newsItemId, array $jobs): void
    {
        if (empty($jobs)) {
            AnalyzeMediaForNewsItemJob::dispatch($newsItemId);

            return;
        }

        app(MediaPipelineProgressTracker::class)->start($newsItemId, count($jobs));

        foreach ($jobs as $job) {
            dispatch($job);
        }
    }

    public function failed(Throwable $exception): void
    {
        MediaProcessingLog::record(
            ProcessingStage::Extraction, ProcessingLogStatus::Failed,
            newsItemId: $this->newsItemId,
            message: $exception->getMessage(),
        );
    }
}
