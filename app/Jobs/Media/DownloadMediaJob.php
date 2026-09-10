<?php

namespace App\Jobs\Media;

use App\Enums\MediaProvider;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\MediaAsset;
use App\Models\MediaProcessingLog;
use App\Services\Media\Deduplication\MediaDuplicateDetectionService;
use App\Services\Media\MediaDownloader;
use App\Services\Media\MediaMetadataExtractor;
use App\Services\Media\MediaPipelineProgressTracker;
use App\Services\Media\Storage\MediaStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Downloads, dedupes, and extracts metadata for a single image/document
 * asset. Videos never come through here — see ProcessVideoJob — because
 * their size/decision logic and worker resource needs are fundamentally
 * different (see docs/MEDIA_ARCHITECTURE.md "Queues & concurrency").
 */
class DownloadMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $mediaAssetId,
        public readonly string $newsItemId,
    ) {
        $this->onQueue(config('media.queues.download'));
    }

    public function handle(
        MediaDownloader $downloader,
        MediaDuplicateDetectionService $duplicateDetection,
        MediaMetadataExtractor $metadataExtractor,
        MediaStorageService $storage,
    ): void {
        $asset = MediaAsset::findOrFail($this->mediaAssetId);

        $this->process($asset, $downloader, $duplicateDetection, $metadataExtractor, $storage);

        $this->notifyPipelineProgress();
    }

    private function process(
        MediaAsset $asset,
        MediaDownloader $downloader,
        MediaDuplicateDetectionService $duplicateDetection,
        MediaMetadataExtractor $metadataExtractor,
        MediaStorageService $storage,
    ): void {
        if ($asset->status !== MediaStatus::Pending) {
            return; // already processed (e.g. resolved as a duplicate by a concurrent extraction run)
        }

        $asset->update(['status' => MediaStatus::Downloading]);

        $maxBytes = $asset->type === MediaType::Document
            ? config('media.limits.max_document_download_bytes')
            : config('media.limits.max_image_download_bytes');

        $binary = $downloader->downloadToMemory($asset->original_url, $maxBytes);
        $hash = hash('sha256', $binary);

        if ($duplicate = $duplicateDetection->findByContentHash($hash)) {
            $asset->update([
                'status' => MediaStatus::Duplicate,
                'duplicate_of_id' => $duplicate->canonical->id,
                'content_hash' => $hash,
                'processed_at' => now(),
            ]);
            $this->log($asset, ProcessingLogStatus::Skipped, "exact duplicate of {$duplicate->canonical->id} ({$duplicate->reason})");

            return;
        }

        if ($duplicate = $duplicateDetection->findByPerceptualHash($binary, $asset->type)) {
            $asset->update([
                'status' => MediaStatus::Duplicate,
                'duplicate_of_id' => $duplicate->canonical->id,
                'content_hash' => $hash,
                'processed_at' => now(),
            ]);
            $this->log($asset, ProcessingLogStatus::Skipped, "perceptual duplicate of {$duplicate->canonical->id} ({$duplicate->reason})");

            return;
        }

        $extension = $this->guessExtension($asset, $binary);
        $storagePath = $storage->putOriginal($binary, $hash, $extension);

        $asset->update([
            'provider' => MediaProvider::Downloaded,
            'status' => MediaStatus::Processing,
            'storage_path' => $storagePath,
            'content_hash' => $hash,
            'file_extension' => $extension,
            'file_size' => strlen($binary),
            'downloaded_at' => now(),
        ]);

        if (in_array($asset->type, [MediaType::Image, MediaType::Gif, MediaType::Infographic, MediaType::Chart], true)) {
            $asset->perceptual_hash = $duplicateDetection->computePerceptualHash($binary);
            $metadataExtractor->extractForImage($asset, $binary);
        }

        $asset->update(['status' => MediaStatus::Ready, 'processed_at' => now()]);
        $this->log($asset, ProcessingLogStatus::Succeeded);
    }

    private function guessExtension(MediaAsset $asset, string $binary): string
    {
        if ($asset->file_extension) {
            return $asset->file_extension;
        }

        $pathExt = pathinfo(parse_url($asset->original_url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
        if ($pathExt) {
            return strtolower($pathExt);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($binary);

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function log(MediaAsset $asset, ProcessingLogStatus $status, ?string $message = null): void
    {
        MediaProcessingLog::record(ProcessingStage::Download, $status, mediaAssetId: $asset->id, message: $message);
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
