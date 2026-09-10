<?php

namespace App\Services\Media\Scoring;

use App\Models\MediaAsset;
use App\Models\NewsItem;

/**
 * The expensive step of the relevance pipeline: an actual multimodal/vision
 * model call. Only ever invoked by MediaRelevanceScorer for the small
 * shortlist that survives cheap rule-based scoring — see
 * media.relevance.max_candidates_for_ai_analysis and
 * docs/MEDIA_ARCHITECTURE.md "AI relevance pipeline".
 */
interface VisionRelevanceAnalyzerInterface
{
    /** @return float 0..1 */
    public function scoreRelevance(MediaAsset $asset, NewsItem $newsItem): float;
}
