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
use App\Services\Media\Scoring\ImageRoleClassifier;
use App\Services\Media\Scoring\MediaRelevanceScorer;
use App\Support\Observability\PipelineLogger;
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
        ImageRoleClassifier $roleClassifier,
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
        $existingAssetCount = 0;
        $ignoredAssetCount = 0;
        $createdAssetCount = 0;
        $readyReferenceCount = 0;
        $rejectedRoles = [];

        foreach ($candidates as $extracted) {
            // What the image IS, before anything about how good it looks. An
            // avatar or a site icon is rejected here rather than downstream so
            // it never becomes a row to store, score, probe and rank — and,
            // more to the point, never competes for an album slot with the
            // article's own pictures.
            if ($extracted->type->isVisual()) {
                $role = $roleClassifier->classify(
                    $extracted->url,
                    $extracted->altText,
                    $extracted->width,
                    $extracted->height,
                );

                if (! $role->isPublishable()) {
                    $rejectedRoles[$role->value] = ($rejectedRoles[$role->value] ?? 0) + 1;
                    $ignoredAssetCount++;

                    continue;
                }
            }

            $canonicalUrl = $duplicateDetection->normalizeUrl($extracted->url);

            if ($existingMatch = $duplicateDetection->findByUrl($extracted->url, $extracted->type)) {
                $this->attachToArticle($newsItem, $existingMatch->canonical, $extracted, $relevanceScorer, $total);
                $existingAssetCount++;

                continue;
            }

            $action = $policy->decide($extracted, $newsItem->source);

            if ($action === MediaIngestionAction::Ignore) {
                $ignoredAssetCount++;

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
            $createdAssetCount++;

            $this->attachToArticle($newsItem, $asset, $extracted, $relevanceScorer, $total);

            if ($action === MediaIngestionAction::Download) {
                $followUpJobs[] = new DownloadMediaJob($asset->id, $newsItem->id);
            } elseif ($extracted->type === MediaType::Video || $extracted->type === MediaType::Audio) {
                $followUpJobs[] = new ProcessVideoJob($asset->id, $newsItem->id);
            } else {
                // Reference-only image/embed: nothing to fetch, immediately usable.
                $asset->update(['status' => MediaStatus::Ready, 'processed_at' => now()]);
                $readyReferenceCount++;
            }
        }

        $this->dispatchFollowUp($newsItem->id, $followUpJobs);

        PipelineLogger::info('media.extraction_completed', [
            'news_item_id' => $newsItem->id,
            'candidate_count' => $total,
            'existing_asset_count' => $existingAssetCount,
            'created_asset_count' => $createdAssetCount,
            'ignored_asset_count' => $ignoredAssetCount,
            'ready_reference_count' => $readyReferenceCount,
            'follow_up_job_count' => count($followUpJobs),
            'rejected_roles' => $rejectedRoles,
        ]);
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
        $reason = PipelineLogger::exceptionMessage($exception);

        MediaProcessingLog::record(
            ProcessingStage::Extraction, ProcessingLogStatus::Failed,
            newsItemId: $this->newsItemId,
            message: $reason,
        );

        PipelineLogger::exception('media.extraction_failed', $exception, ['news_item_id' => $this->newsItemId]);

        // Extraction is where media comes from, not where the article does.
        // The publishing scheduler only ever considers items whose media
        // analysis has completed, so stopping here would strand a perfectly
        // publishable article forever — silently, with no failed job left to
        // retry, because this handler runs after the last attempt. Carry on to
        // analysis instead: with no usable media the article posts as text,
        // which is the whole reason PostMediaType::None exists.
        AnalyzeMediaForNewsItemJob::dispatch($this->newsItemId);

        PipelineLogger::warning('media.extraction_failed_text_only_continuation', [
            'news_item_id' => $this->newsItemId,
        ]);
    }
}
