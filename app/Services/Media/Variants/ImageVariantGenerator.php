<?php

namespace App\Services\Media\Variants;

use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * Generates derived renditions from an original image's bytes. Called lazily
 * — only when a variant is actually needed (see MediaSelectionService and
 * GenerateMediaVariantJob) — never eagerly for every downloaded image.
 */
class ImageVariantGenerator
{
    public function __construct(private readonly ImageManager $manager) {}

    /** Long-edge capped, web-quality JPEG. */
    public function optimized(string $binaryContents, int $maxDimension = 1600, int $quality = 82): string
    {
        $image = $this->manager->read($binaryContents)->scaleDown(width: $maxDimension, height: $maxDimension);

        return (string) $image->encode(new JpegEncoder(quality: $quality));
    }

    /** Square-cropped small preview for grids/galleries. */
    public function thumbnail(string $binaryContents, int $size = 320, int $quality = 80): string
    {
        $image = $this->manager->read($binaryContents)->cover($size, $size);

        return (string) $image->encode(new JpegEncoder(quality: $quality));
    }

    /**
     * Tuned to Telegram's sendPhoto sweet spot: comfortably under the 10MB cap
     * and the 10000px width+height sum, and re-encoded so an oversized/odd
     * source format never causes an upload rejection.
     */
    public function telegram(string $binaryContents, int $maxDimension = 1280, int $quality = 85): string
    {
        $image = $this->manager->read($binaryContents)->scaleDown(width: $maxDimension, height: $maxDimension);

        return (string) $image->encode(new JpegEncoder(quality: $quality));
    }

    /** Same dimensions, aggressively compressed — used as a last-resort fallback when file size, not dimensions, is the problem. */
    public function compressed(string $binaryContents, int $quality = 55): string
    {
        $image = $this->manager->read($binaryContents);

        return (string) $image->encode(new JpegEncoder(quality: $quality));
    }

    public function dimensions(string $binaryContents): array
    {
        $image = $this->manager->read($binaryContents);

        return [$image->width(), $image->height()];
    }
}
