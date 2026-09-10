<?php

namespace App\Jobs\Media;

use App\Enums\MediaStatus;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\MediaAsset;
use App\Models\MediaProcessingLog;
use App\Services\Media\MediaPipelineProgressTracker;
use App\Services\Media\Video\VideoProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs exclusively on the isolated 'video-processing' queue (see
 * docs/MEDIA_ARCHITECTURE.md "Queues & concurrency") — ffmpeg probing/
 * transcoding is CPU- and memory-heavy and must never share a worker pool
 * with fast jobs like DownloadMediaJob, or a burst of large videos would
 * starve ordinary image/text processing.
 */
class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public array $backoff = [30, 300];

    public function __construct(
        public readonly string $mediaAssetId,
        public readonly string $newsItemId,
    ) {
        $this->onQueue(config('media.queues.video_processing'));
    }

    public function handle(VideoProcessingService $service): void
    {
        $asset = MediaAsset::findOrFail($this->mediaAssetId);

        $this->process($asset, $service);

        $this->notifyPipelineProgress();
    }

    private function process(MediaAsset $asset, VideoProcessingService $service): void
    {
        if ($asset->status !== MediaStatus::Pending) {
            return;
        }

        $asset->update(['status' => MediaStatus::Processing]);

        $service->process($asset->fresh());

        MediaProcessingLog::record(
            ProcessingStage::Download, ProcessingLogStatus::Succeeded,
            mediaAssetId: $asset->id,
            message: 'video ingestion decision: '.data_get($asset->refresh()->metadata, 'ingestion_reason'),
        );
    }

    /** Fires exactly once per job instance: here on success, or from failed() once retries are exhausted. */
    private function notifyPipelineProgress(): void
    {
        if (app(MediaPipelineProgressTracker::class)->complete($this->newsItemId)) {
            AnalyzeMediaForNewsItemJob::dispatch($this->newsItemId);
        }
    }

    public function failed(Throwable $exception): void
    {
        $asset = MediaAsset::find($this->mediaAssetId);
        $asset?->update(['status' => MediaStatus::Failed, 'failure_reason' => $exception->getMessage()]);

        MediaProcessingLog::record(
            ProcessingStage::Download, ProcessingLogStatus::Failed,
            mediaAssetId: $this->mediaAssetId,
            message: $exception->getMessage(),
            attempt: $this->attempts(),
        );

        $this->notifyPipelineProgress();
    }
}
