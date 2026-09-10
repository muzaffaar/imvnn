<?php

namespace App\Services\Media\Scoring;

use App\Enums\MediaType;
use App\Models\MediaAsset;

/**
 * Context-independent "is this a good, safe, publishable asset" score.
 *
 * media_quality_score =
 *     0.25 x relevance             (cached from MediaRelevanceScorer/RelevanceAnalysisService)
 *   + 0.15 x resolution
 *   + 0.15 x visual_quality        (sharpness proxy, corruption/watermark penalties, file-size fitness)
 *   + 0.15 x source_reliability
 *   + 0.10 x telegram_compatibility
 *   + 0.10 x uniqueness            (penalizes assets that turn out to be widely-duplicated stock/logo images)
 *   + 0.10 x aspect_ratio          (added vs. the original formula: a good-resolution, on-topic image that's
 *                                   cropped square or ultra-tall still reads badly as a Telegram post image)
 *
 * Weights come from config('media.quality_weights') so they can be tuned
 * without a deploy. See docs/MEDIA_ARCHITECTURE.md "Quality formula".
 */
class MediaQualityScorer
{
    public function __construct(
        private readonly TelegramCompatibilityChecker $telegramChecker,
    ) {}

    public function score(MediaAsset $asset): float
    {
        $weights = config('media.quality_weights');

        $relevance = $asset->relevance_score ?? 0.5;
        $resolution = $this->resolutionScore($asset);
        $visualQuality = $this->visualQualityScore($asset);
        $sourceReliability = $asset->source?->reliabilityScoreNormalized() ?? 0.5;
        $telegramCompatibility = $this->telegramChecker->check($asset)['score'];
        $uniqueness = $this->uniquenessScore($asset);
        $aspectRatio = $this->aspectRatioScore($asset);

        $score = $weights['relevance'] * $relevance
            + $weights['resolution'] * $resolution
            + $weights['visual_quality'] * $visualQuality
            + $weights['source_reliability'] * $sourceReliability
            + $weights['telegram_compatibility'] * $telegramCompatibility
            + $weights['uniqueness'] * $uniqueness
            + $weights['aspect_ratio'] * $aspectRatio;

        return round(min(1.0, max(0.0, $score)), 4);
    }

    private function resolutionScore(MediaAsset $asset): float
    {
        if (! $asset->width || ! $asset->height) {
            return 0.4; // unknown resolution (e.g. reference-only video) — neutral-low, not disqualifying
        }

        $pixels = $asset->width * $asset->height;
        $idealPixels = 1920 * 1080;

        return round(min(1.0, $pixels / $idealPixels), 4);
    }

    /**
     * Folds sharpness, corruption, watermark, and file-size fitness into one
     * term. Sharpness here is a cheap variance-based proxy computed at
     * download time and cached in metadata (see MediaMetadataExtractor) —
     * not a substitute for a real no-reference image-quality model, which is
     * unnecessary cost for this pipeline's purpose (picking a "good enough"
     * Telegram post image, not professional photo curation).
     */
    private function visualQualityScore(MediaAsset $asset): float
    {
        if ($asset->status->isTerminalFailure()) {
            return 0.0; // corruption_penalty: failed to decode/validate at all
        }

        $sharpness = (float) data_get($asset->metadata, 'sharpness_score', 0.7);
        $watermarkPenalty = data_get($asset->metadata, 'watermark_detected', false) ? 0.3 : 0.0;
        $fileSizeScore = $this->fileSizeFitness($asset);

        $score = ($sharpness * 0.5) + ($fileSizeScore * 0.5) - $watermarkPenalty;

        return round(min(1.0, max(0.0, $score)), 4);
    }

    private function fileSizeFitness(MediaAsset $asset): float
    {
        if (! $asset->file_size) {
            return 0.6;
        }

        // Sweet spot: large enough to not be a placeholder/icon, small enough to
        // not be an unnecessarily heavy upload. Tuned for images; video is judged
        // by Telegram compatibility instead (see TelegramCompatibilityChecker).
        if ($asset->type !== MediaType::Image && $asset->type !== MediaType::Gif) {
            return 0.8;
        }

        return match (true) {
            $asset->file_size < 8 * 1024 => 0.2,        // likely an icon/tracking pixel
            $asset->file_size < 3 * 1024 * 1024 => 1.0,
            $asset->file_size < 10 * 1024 * 1024 => 0.7,
            default => 0.4,
        };
    }

    /** Penalizes assets that many other extracted copies resolved as duplicates of. */
    private function uniquenessScore(MediaAsset $asset): float
    {
        if ($asset->duplicate_of_id !== null) {
            return 0.0; // shouldn't be scored for selection at all; see MediaAsset::isUsable()
        }

        $duplicateCount = $asset->relationLoaded('duplicates')
            ? $asset->duplicates->count()
            : $asset->duplicates()->count();

        return round(max(0.0, 1.0 - (0.15 * $duplicateCount)), 4);
    }

    private function aspectRatioScore(MediaAsset $asset): float
    {
        if (! $asset->width || ! $asset->height || $asset->type === MediaType::Video) {
            return 0.7;
        }

        $ideal = config('media.ideal_aspect_ratio');
        $actual = $asset->width / max(1, $asset->height);

        $deviation = abs(log($actual / $ideal));

        return round(max(0.0, 1.0 - ($deviation / log(4))), 4);
    }
}
