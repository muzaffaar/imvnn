<?php

namespace App\Services\Media\Scoring;

use App\Models\NewsItem;

/**
 * Finalizes relevance scores for a news item's media once assets are
 * downloaded/processed. Implements the pipeline from the spec:
 *
 *   cheap rule-based score (already set at extraction) -> sort -> vision model
 *   only for the top-N candidates that clear min_score_for_ai_analysis.
 *
 * A candidate that's already obviously irrelevant (banner ad shape, logo-sized
 * image, near-zero keyword overlap) never reaches the vision model at all.
 */
class RelevanceAnalysisService
{
    public function __construct(
        private readonly VisionRelevanceAnalyzerInterface $visionAnalyzer,
    ) {}

    public function analyzeForNewsItem(NewsItem $newsItem): void
    {
        $maxCandidates = config('media.relevance.max_candidates_for_ai_analysis');
        $minScore = config('media.relevance.min_score_for_ai_analysis');

        $pivots = $newsItem->mediaAssets()
            ->wherePivotNotNull('relevance_score')
            ->orderByPivot('relevance_score', 'desc')
            ->get();

        $promoted = 0;

        foreach ($pivots as $mediaAsset) {
            $pivot = $mediaAsset->pivot;

            if ($promoted >= $maxCandidates || $pivot->relevance_score < $minScore) {
                continue;
            }

            $aiScore = $this->visionAnalyzer->scoreRelevance($mediaAsset, $newsItem);

            // Blend rather than overwrite: a single model call shouldn't fully override
            // multiple independent cheap signals, but should meaningfully move the score.
            $finalScore = round(($pivot->relevance_score * 0.4) + ($aiScore * 0.6), 4);

            $pivot->update(['relevance_score' => $finalScore]);

            if ($finalScore > ($mediaAsset->relevance_score ?? 0)) {
                $mediaAsset->update(['relevance_score' => $finalScore]);
            }

            $promoted++;
        }
    }
}
