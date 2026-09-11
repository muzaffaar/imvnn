<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\MediaAsset;
use App\Models\MediaMetadataEntry;

/**
 * Populates technical metadata for a freshly-downloaded asset: dimensions,
 * mime type, sharpness proxy, and a raw EXIF/finfo dump kept in media_metadata
 * for anything that isn't hot-path enough to deserve its own column.
 */
class MediaMetadataExtractor
{
    public function __construct(
        private readonly SharpnessEstimator $sharpnessEstimator,
    ) {}

    public function extractForImage(MediaAsset $asset, string $binaryContents): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($binaryContents) ?: $asset->mime_type;

        [$width, $height] = $this->imageDimensions($binaryContents);
        $sharpness = $this->sharpnessEstimator->estimate($binaryContents, $asset->id);

        $asset->fill([
            'mime_type' => $mimeType,
            'width' => $width ?? $asset->width,
            'height' => $height ?? $asset->height,
        ]);

        $asset->metadata = array_merge($asset->metadata ?? [], [
            'sharpness_score' => $sharpness,
            'watermark_detected' => false, // heuristic hook only — see docs/MEDIA_ARCHITECTURE.md
        ]);

        $asset->save();

        $exif = $this->safeExifRead($binaryContents);
        if ($exif) {
            MediaMetadataEntry::updateOrCreate(
                ['media_asset_id' => $asset->id, 'key' => 'exif'],
                ['value' => $exif, 'extracted_by' => 'media_metadata_extractor'],
            );
        }
    }

    /** @return array{0: int|null, 1: int|null} */
    private function imageDimensions(string $binaryContents): array
    {
        $info = @getimagesizefromstring($binaryContents);

        return $info ? [$info[0], $info[1]] : [null, null];
    }

    private function safeExifRead(string $binaryContents): ?array
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $binaryContents);
        rewind($stream);

        try {
            $data = @exif_read_data($stream, null, true);

            return $data ?: null;
        } catch (\Throwable) {
            return null;
        } finally {
            fclose($stream);
        }
    }

    public function detectType(string $mimeType): MediaType
    {
        return match (true) {
            $mimeType === 'image/gif' => MediaType::Gif,
            str_starts_with($mimeType, 'image/') => MediaType::Image,
            str_starts_with($mimeType, 'video/') => MediaType::Video,
            str_starts_with($mimeType, 'audio/') => MediaType::Audio,
            default => MediaType::Document,
        };
    }
}
