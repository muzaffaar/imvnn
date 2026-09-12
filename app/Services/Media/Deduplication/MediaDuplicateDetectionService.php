<?php

namespace App\Services\Media\Deduplication;

use App\Enums\MediaType;
use App\Models\MediaAsset;
use Carbon\CarbonImmutable;

/**
 * Runs duplicate detection cheapest-first, short-circuiting on the first hit —
 * see docs/MEDIA_ARCHITECTURE.md "Duplicate detection" for when each level
 * fires and why Level 4 is opt-in.
 */
class MediaDuplicateDetectionService
{
    public function __construct(
        private readonly UrlNormalizer $urlNormalizer,
        private readonly PerceptualHasher $perceptualHasher,
    ) {}

    /** Level 1 — can run the instant a candidate is extracted, before any download. */
    public function findByUrl(string $url, MediaType $type): ?DuplicateMatch
    {
        $canonical = $this->urlNormalizer->normalize($url);

        $existing = MediaAsset::query()
            ->where('type', $type)
            ->whereNull('duplicate_of_id')
            ->where('canonical_url', $canonical)
            ->first();

        return $existing
            ? new DuplicateMatch($existing, level: 1, confidence: 1.0, reason: 'canonical_url match')
            : null;
    }

    /** Level 2 — exact byte-for-byte match. Requires the file to already be downloaded. */
    public function findByContentHash(string $sha256): ?DuplicateMatch
    {
        $existing = MediaAsset::query()
            ->whereNull('duplicate_of_id')
            ->where('content_hash', $sha256)
            ->first();

        return $existing
            ? new DuplicateMatch($existing, level: 2, confidence: 1.0, reason: 'content_hash match')
            : null;
    }

    /**
     * Level 3 — resized/recompressed/lightly-cropped copies. Images and gifs
     * only; only compares against recent assets (perceptual_hash_lookback_days)
     * since a wire photo circulating from 3 years ago isn't worth the scan cost.
     */
    public function findByPerceptualHash(string $binaryContents, MediaType $type): ?DuplicateMatch
    {
        if (! in_array($type, [MediaType::Image, MediaType::Gif, MediaType::Infographic, MediaType::Chart], true)) {
            return null;
        }

        $hash = $this->perceptualHasher->hashFromBinary($binaryContents);

        // A featureless image — a plain backdrop, a flat gradient — hashes to
        // near-uniform bits and lands within the threshold of every other such
        // image. Claiming those are the same picture would discard a real
        // asset, so an image with nothing to fingerprint is simply not one
        // this level can judge.
        if (! $this->perceptualHasher->isDistinctive($hash)) {
            return null;
        }

        $threshold = config('media.deduplication.perceptual_hash_hamming_threshold');
        $lookbackDays = config('media.deduplication.perceptual_hash_lookback_days');

        $candidates = MediaAsset::query()
            ->where('type', $type)
            ->whereNull('duplicate_of_id')
            ->whereNotNull('perceptual_hash')
            ->where('created_at', '>=', CarbonImmutable::now()->subDays($lookbackDays))
            ->get(['id', 'perceptual_hash']);

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            if (! $this->perceptualHasher->isDistinctive($candidate->perceptual_hash)) {
                continue;
            }

            $distance = $this->perceptualHasher->hammingDistance($hash, $candidate->perceptual_hash);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        if (! $best || $bestDistance > $threshold) {
            return null;
        }

        // 0 distance -> confidence 1.0; at the threshold -> confidence ~0.5.
        $confidence = 1.0 - ($bestDistance / max(1, $threshold * 2));

        return new DuplicateMatch($best, level: 3, confidence: round($confidence, 2), reason: "dHash distance {$bestDistance}/{$threshold}");
    }

    public function computePerceptualHash(string $binaryContents): string
    {
        return $this->perceptualHasher->hashFromBinary($binaryContents);
    }

    public function normalizeUrl(string $url): string
    {
        return $this->urlNormalizer->normalize($url);
    }
}
