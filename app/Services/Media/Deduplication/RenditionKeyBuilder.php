<?php

namespace App\Services\Media\Deduplication;

use App\Models\MediaAsset;

/**
 * A "same picture" key for assets we never downloaded, and therefore have
 * no content hash or perceptual hash for.
 *
 * CDNs routinely serve one image under several URLs — confirmed on
 * research.google, where every article figure appears as both
 * `original_images/GlucoFM1_Overview.png` and
 * `images/GlucoFM1_Overview.width-1250.png`, and on blog.google as
 * `WeatherNext3_Title.width-1300.png` / `WeatherNext3_Title.width-200.format-webp.webp`.
 * Level 1 (URL) dedup misses these because the URLs genuinely differ, and
 * Levels 2/3 (content/perceptual hash) never run for reference-only assets
 * because we deliberately never fetch their bytes. Without this, the same
 * picture is attached to a Telegram post two or three times.
 *
 * Deliberately keyed on host + normalized *filename*, ignoring the
 * directory — that's the whole point, since renditions of one image live in
 * different directories. To keep that from merging two genuinely different
 * images that happen to share a common filename (`hero.jpg` under two
 * article paths), a name shorter than MIN_DISTINCTIVE_LENGTH falls back to
 * the full path instead.
 */
class RenditionKeyBuilder
{
    private const MIN_DISTINCTIVE_LENGTH = 8;

    /** Rendition markers CDNs append; applied repeatedly since they chain. */
    private const RENDITION_PATTERNS = [
        '/\.(width|height|max|min|fill|crop|scale|resize)-\d+(x\d+)?$/i',
        // A bare keyword left behind once its dimensions have been stripped by
        // a later pattern in this same list. `Hero_Image_4.max-600x600.format-webp`
        // loses `.format-webp`, then `-600x600`, which leaves `.max` stranded
        // where the first pattern can no longer see it — so that rendition kept
        // its own key and the same hero image reached an album twice.
        '/\.(width|height|max|min|fill|crop|scale|resize)$/i',
        '/\.format-[a-z0-9]+$/i',
        '/\.[0-9a-f]{6,40}$/i',                 // cache-busting content hash: Foo.2e16d0ba
        '/[-_]\d{2,4}x\d{2,4}$/',               // Foo-600x600
        '/@\d+x$/i',                            // Foo@2x
        '/[-_](thumb|thumbnail|small|medium|large|scaled|preview|resized)$/i',
    ];

    public function keyFor(MediaAsset $asset): string
    {
        // When we actually hold the bytes, exact/perceptual identity beats
        // any URL guessing.
        if ($asset->content_hash) {
            return 'hash:'.$asset->content_hash;
        }

        if ($asset->perceptual_hash) {
            return 'phash:'.$asset->perceptual_hash;
        }

        if ($asset->external_provider && $asset->external_id) {
            return "{$asset->external_provider}:{$asset->external_id}";
        }

        return 'url:'.$this->visualKeyFromUrl($asset->original_url);
    }

    private function visualKeyFromUrl(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $path = parse_url($url, PHP_URL_PATH) ?? $url;
        $name = pathinfo($path, PATHINFO_FILENAME);

        $normalized = $this->stripRenditionMarkers($this->stripSizeSuffix($name));

        if (mb_strlen($normalized) < self::MIN_DISTINCTIVE_LENGTH) {
            return $host.'|'.strtolower(trim($path, '/'));
        }

        return $host.'|'.mb_strtolower($normalized);
    }

    /**
     * Google's image CDNs encode the rendition as an `=`-delimited option
     * string glued straight onto the image id, with no dot and no extension:
     * `…vOjFcTcd…=w1200-h630-n-nu-rw`. Every pattern in RENDITION_PATTERNS
     * anchors on a `.` or a trailing `-NNNxNNN`, so none of them touch it, and
     * one image served at five sizes produced five different keys — which is
     * exactly how the same picture reached a Telegram album twice (observed on
     * a DeepMind article: `=w2000-h1260` and `=w1920-h1080` of one figure
     * occupied two of four album slots).
     *
     * Splitting on the first `=` is safe because the id itself never contains
     * one: it is base64url, whose alphabet excludes `=` except as terminal
     * padding, which these ids do not carry.
     */
    private function stripSizeSuffix(string $name): string
    {
        $position = strpos($name, '=');

        if ($position === false || $position === 0) {
            return $name;
        }

        return substr($name, 0, $position);
    }

    private function stripRenditionMarkers(string $name): string
    {
        // Markers chain (Foo.width-200.format-webp), so keep stripping until
        // a full pass changes nothing.
        do {
            $before = $name;

            foreach (self::RENDITION_PATTERNS as $pattern) {
                $name = preg_replace($pattern, '', $name) ?? $name;
            }
        } while ($name !== $before && $name !== '');

        return $name;
    }
}
