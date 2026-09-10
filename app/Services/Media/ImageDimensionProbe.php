<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Services\Http\BoundedHttpFetcher;
use Illuminate\Support\Facades\Log;

/**
 * Reads an image's real dimensions without downloading it.
 *
 * Most assets here are reference-only: we deliberately never copy their
 * bytes (copyright), so width, height, mime type and file size stay NULL —
 * which quietly disables half of MediaQualityScorer. Resolution scores a
 * flat 0.4, aspect ratio a flat 0.7, and Telegram compatibility can't be
 * judged at all, so a 60x40 tracking pixel and a 2000x1200 hero photo rank
 * identically, and images Telegram will reject outright (its width+height
 * ≤ 10000 and aspect-ratio limits) sail through to a failed send.
 *
 * Every image format stores its dimensions in a header at the very start of
 * the file — PNG within 24 bytes, GIF within 10, JPEG/WebP within a few KB —
 * so a ranged request for the first chunk is enough. That's a metadata
 * probe, not a copy: nothing is stored, and the bytes are discarded once
 * the header is parsed.
 *
 * Called only for the handful of candidates of an article actually being
 * published (see MediaSelectionService), not for every extracted image, so
 * the cost is a few ranged requests per post rather than per article.
 */
class ImageDimensionProbe
{
    private const PROBE_BYTES = 65536;

    public function __construct(private readonly BoundedHttpFetcher $fetcher) {}

    /** @return bool whether the asset was updated */
    public function probe(MediaAsset $asset): bool
    {
        if ($asset->width && $asset->height) {
            return false;
        }

        try {
            $head = $this->fetcher->downloadRange($asset->original_url, self::PROBE_BYTES);
        } catch (\Throwable $e) {
            Log::info("[image-probe] could not probe {$asset->original_url}: {$e->getMessage()}");

            return false;
        }

        $info = @getimagesizefromstring($head['body']);

        if (! $info || empty($info[0]) || empty($info[1])) {
            return false;
        }

        $asset->update([
            'width' => $info[0],
            'height' => $info[1],
            'mime_type' => $asset->mime_type ?: ($info['mime'] ?? null),
            'file_size' => $asset->file_size ?: $head['total_size'],
        ]);

        return true;
    }
}
