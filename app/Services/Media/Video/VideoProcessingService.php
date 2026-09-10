<?php

namespace App\Services\Media\Video;

use App\Enums\MediaProvider;
use App\Enums\MediaStatus;
use App\Enums\MediaVariantType;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\Deduplication\MediaDuplicateDetectionService;
use App\Services\Media\MediaDownloader;
use App\Services\Media\Scoring\TelegramCompatibilityChecker;
use App\Services\Media\Storage\MediaStorageService;

/**
 * Owns the full "should we download this video, and if so what do we do with
 * it" workflow. Runs exclusively inside ProcessVideoJob on the isolated
 * video-processing queue — see docs/MEDIA_ARCHITECTURE.md "Queues & concurrency".
 */
class VideoProcessingService
{
    public function __construct(
        private readonly VideoDecisionService $decisionService,
        private readonly MediaDownloader $downloader,
        private readonly FfmpegService $ffmpeg,
        private readonly MediaStorageService $storage,
        private readonly MediaDuplicateDetectionService $duplicateDetection,
        private readonly TelegramCompatibilityChecker $telegramChecker,
    ) {}

    public function process(MediaAsset $asset): void
    {
        $probedSize = $this->probeSize($asset);
        $plan = $this->decisionService->decide($asset, $probedSize, $asset->source);

        $asset->update(['metadata' => array_merge($asset->metadata ?? [], ['ingestion_reason' => $plan->reason])]);

        if ($plan->shouldFetchThumbnail) {
            $this->fetchThumbnail($asset);
        }

        if (! $plan->shouldDownload) {
            $asset->update(['status' => MediaStatus::Ready, 'processed_at' => now()]);

            return;
        }

        $this->downloadAndProcess($asset, $plan);
    }

    private function probeSize(MediaAsset $asset): ?int
    {
        $head = $this->downloader->head($asset->original_url);

        return $head['content_length'] ?? null;
    }

    private function fetchThumbnail(MediaAsset $asset): void
    {
        $thumbnailUrl = data_get($asset->metadata, 'thumbnail_url');
        if (! $thumbnailUrl) {
            return;
        }

        try {
            $bytes = $this->downloader->downloadToMemory($thumbnailUrl, 2 * 1024 * 1024);
            $hash = hash('sha256', $bytes);
            $path = $this->storage->putVariant($bytes, MediaVariantType::Thumbnail, $hash, 'jpg');

            MediaVariant::updateOrCreate(
                ['media_asset_id' => $asset->id, 'variant_type' => MediaVariantType::Thumbnail],
                ['storage_path' => $path, 'mime_type' => 'image/jpeg', 'format' => 'jpeg', 'file_size' => strlen($bytes), 'hash' => $hash],
            );
        } catch (\Throwable) {
            // Thumbnail is a nice-to-have here; a missing one shouldn't fail the whole job.
        }
    }

    private function downloadAndProcess(MediaAsset $asset, VideoIngestionPlan $plan): void
    {
        $maxBytes = config('media.limits.max_video_download_bytes');
        $tempPath = tempnam(sys_get_temp_dir(), 'video_');

        try {
            $this->downloader->downloadToFile($asset->original_url, $tempPath, $maxBytes);

            $hash = hash_file('sha256', $tempPath);

            if ($duplicate = $this->duplicateDetection->findByContentHash($hash)) {
                $asset->update([
                    'status' => MediaStatus::Duplicate,
                    'duplicate_of_id' => $duplicate->canonical->id,
                    'content_hash' => $hash,
                    'processed_at' => now(),
                ]);

                return;
            }

            $probe = $this->ffmpeg->probe($tempPath);
            $fileSize = filesize($tempPath);
            $extension = pathinfo(parse_url($asset->original_url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'mp4';

            $storagePath = $this->storage->putOriginal(file_get_contents($tempPath), $hash, $extension);

            $asset->update([
                'provider' => MediaProvider::Downloaded,
                'storage_path' => $storagePath,
                'content_hash' => $hash,
                'file_size' => $fileSize,
                'width' => $probe['width'] ?? $asset->width,
                'height' => $probe['height'] ?? $asset->height,
                'duration_seconds' => $probe['duration_seconds'] ?? $asset->duration_seconds,
                'mime_type' => "video/{$extension}",
                'file_extension' => $extension,
                'downloaded_at' => now(),
            ]);

            if (! empty($probe)) {
                \App\Models\MediaMetadataEntry::updateOrCreate(
                    ['media_asset_id' => $asset->id, 'key' => 'ffprobe'],
                    ['value' => $probe, 'extracted_by' => 'ffmpeg_service'],
                );
            }

            if ($plan->shouldConsiderTranscode && ! $this->telegramChecker->check($asset->refresh())['compatible']) {
                $this->transcodeForTelegram($asset, $tempPath);
            }

            $asset->update(['status' => MediaStatus::Ready, 'processed_at' => now()]);
        } finally {
            @unlink($tempPath);
        }
    }

    private function transcodeForTelegram(MediaAsset $asset, string $localPath): void
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'video_telegram_').'.mp4';

        try {
            if (! $this->ffmpeg->transcodeForTelegram($localPath, $outputPath)) {
                return;
            }

            $bytes = file_get_contents($outputPath);
            $hash = hash('sha256', $bytes);
            $path = $this->storage->putVariant($bytes, MediaVariantType::Telegram, $hash, 'mp4');

            MediaVariant::updateOrCreate(
                ['media_asset_id' => $asset->id, 'variant_type' => MediaVariantType::Telegram],
                ['storage_path' => $path, 'mime_type' => 'video/mp4', 'format' => 'mp4', 'file_size' => strlen($bytes), 'hash' => $hash],
            );
        } finally {
            @unlink($outputPath);
        }
    }
}
