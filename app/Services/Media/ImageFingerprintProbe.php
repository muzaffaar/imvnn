<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\MediaAsset;
use App\Services\Http\BoundedHttpFetcher;
use App\Services\Media\Deduplication\PerceptualHasher;
use App\Support\Observability\PipelineLogger;
use Throwable;

/**
 * Gives a reference-only image a perceptual fingerprint, so duplicate
 * detection can judge two candidates on what they depict rather than on what
 * a CMS happened to name them.
 *
 * This exists because filename heuristics have a floor they cannot cross.
 * RenditionKeyBuilder collapses renditions of one picture as long as the
 * stems are related, and it does that well. It is helpless when a newsroom
 * publishes the same photograph under two unrelated names — observed on
 * news.mit.edu, where one AI-GUIDE device photo ships as both
 * `images/202608/mit-lincoln-AI-GUIDE.jpg` and
 * `styles/news_article__image_gallery/public/images/202608/AI-GUIDE device.jpeg`.
 * Nothing in either URL says they are the same picture, so both were ranked,
 * both survived every filter, and the album showed the photo twice.
 *
 * The bytes are a means to an end and are never kept: they are read into
 * memory, reduced to 64 bits, and dropped when this method returns. That is
 * the same bargain ImageDimensionProbe already makes for width and height,
 * and it leaves the reference-only copyright stance untouched — no file is
 * written, no storage path is set, and `provider` stays External.
 *
 * Cost is bounded on both axes. MediaSelectionService fingerprints only the
 * few finalists of an article actually being published, and each read stops
 * at `media.deduplication.fingerprint_max_bytes`.
 */
class ImageFingerprintProbe
{
    /** dHash describes still images; video and embeds have nothing to hash. */
    private const HASHABLE_TYPES = [
        MediaType::Image,
        MediaType::Gif,
        MediaType::Infographic,
        MediaType::Chart,
        MediaType::Thumbnail,
    ];

    public function __construct(
        private readonly BoundedHttpFetcher $fetcher,
        private readonly PerceptualHasher $hasher,
    ) {}

    /**
     * Fetches, hashes and discards. Returns whether the asset now carries a
     * fingerprint it did not have before.
     */
    public function fingerprint(MediaAsset $asset): bool
    {
        if ($asset->perceptual_hash || ! in_array($asset->type, self::HASHABLE_TYPES, true)) {
            return false;
        }

        $maxBytes = (int) config('media.deduplication.fingerprint_max_bytes');

        // A known-oversized image is skipped rather than fetched and thrown
        // away at the limit: the read would cost the full budget and yield
        // nothing. Such images are rare enough that the URL heuristics
        // remain a reasonable fallback for them.
        if ($asset->file_size && $asset->file_size > $maxBytes) {
            PipelineLogger::debug('media.fingerprint_skipped', [
                'media_asset_id' => $asset->id,
                'reason' => 'file_size_above_limit',
                'file_size' => $asset->file_size,
                'max_bytes' => $maxBytes,
            ]);

            return false;
        }

        try {
            $binary = $this->fetcher->downloadToMemory(
                $asset->original_url,
                $maxBytes,
                timeoutSeconds: (int) config('media.deduplication.fingerprint_timeout_seconds'),
            );
        } catch (Throwable $e) {
            PipelineLogger::exception('media.fingerprint_fetch_failed', $e, [
                'media_asset_id' => $asset->id,
                'asset_url' => PipelineLogger::url($asset->original_url),
            ], 'warning');

            return false;
        }

        try {
            return $this->storeFingerprint($asset, $binary);
        } finally {
            unset($binary);
        }
    }

    /**
     * Hashes bytes already in hand — used when another step has paid for the
     * transfer, so the fingerprint costs no further request.
     */
    public function storeFingerprint(MediaAsset $asset, string $binary): bool
    {
        if ($asset->perceptual_hash) {
            return false;
        }

        try {
            $hash = $this->hasher->hashFromBinary($binary);
        } catch (Throwable $e) {
            // A truncated, animated or otherwise undecodable body is a normal
            // outcome here, not a pipeline failure: the asset simply keeps
            // being identified by its URL.
            PipelineLogger::exception('media.fingerprint_decode_failed', $e, [
                'media_asset_id' => $asset->id,
                'asset_url' => PipelineLogger::url($asset->original_url),
            ], 'warning');

            return false;
        }

        // A hash without structure would match unrelated flat images, so it is
        // worse than no hash at all — dropping a real photo from an album is a
        // louder failure than publishing one picture twice.
        if (! $this->hasher->isDistinctive($hash)) {
            PipelineLogger::debug('media.fingerprint_skipped', [
                'media_asset_id' => $asset->id,
                'reason' => 'hash_not_distinctive',
            ]);

            return false;
        }

        $attributes = ['perceptual_hash' => $hash];

        // Real dimensions are a free by-product of holding the whole file, and
        // they overwrite rather than merely fill in what ImageDimensionProbe
        // recorded. That probe reads a 64 KB prefix, and a prefix can lie: on
        // the MIT AI-GUIDE photo it parsed an embedded EXIF thumbnail and
        // stored 390x260 for an image that is really 1500x1000 — which then
        // ranked the better rendition below the worse one. A complete file
        // cannot be misread that way.
        $info = @getimagesizefromstring($binary);

        if ($info && ! empty($info[0]) && ! empty($info[1])) {
            $attributes += array_filter([
                'width' => $info[0],
                'height' => $info[1],
                'mime_type' => $info['mime'] ?? $asset->mime_type,
                'file_size' => strlen($binary),
            ]);
        }

        $asset->update($attributes);

        PipelineLogger::debug('media.fingerprint_completed', [
            'media_asset_id' => $asset->id,
            'perceptual_hash' => $hash,
            'width' => $asset->width,
            'height' => $asset->height,
        ]);

        return true;
    }
}
