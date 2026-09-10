<?php

namespace App\Services\Media;

use Intervention\Image\ImageManager;

/**
 * Cheap no-reference sharpness proxy: average absolute gradient between
 * neighboring pixels on a small greyscale downsample. Blurry/flat images
 * (ads, solid-color placeholders, heavily compressed thumbnails) score low;
 * detailed photos score high. This is a heuristic, not a real blur-detection
 * model (e.g. variance-of-Laplacian on the full-resolution image) — it's
 * intentionally cheap because it runs on every downloaded image. See
 * docs/MEDIA_ARCHITECTURE.md "Visual quality" for when to upgrade it.
 */
class SharpnessEstimator
{
    private const SAMPLE_SIZE = 64;

    public function __construct(private readonly ImageManager $manager) {}

    /** @return float 0..1 */
    public function estimate(string $binaryContents): float
    {
        try {
            $image = $this->manager->read($binaryContents)
                ->resize(self::SAMPLE_SIZE, self::SAMPLE_SIZE)
                ->greyscale();
        } catch (\Throwable) {
            return 0.0; // undecodable -> corruption, not sharpness — caller treats 0 as "bad"
        }

        $total = 0;
        $samples = 0;

        for ($y = 0; $y < self::SAMPLE_SIZE; $y++) {
            $prev = null;
            for ($x = 0; $x < self::SAMPLE_SIZE; $x++) {
                $value = $image->pickColor($x, $y)->toArray()[0];
                if ($prev !== null) {
                    $total += abs($value - $prev);
                    $samples++;
                }
                $prev = $value;
            }
        }

        $averageGradient = $samples > 0 ? $total / $samples : 0;

        // Empirically, an average neighbor-gradient above ~25 (out of 255) on a
        // 64x64 downsample reads as "has real detail"; scale linearly and cap at 1.
        return round(min(1.0, $averageGradient / 25), 4);
    }
}
