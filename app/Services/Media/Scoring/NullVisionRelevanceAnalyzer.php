<?php

namespace App\Services\Media\Scoring;

use App\Models\MediaAsset;
use App\Models\NewsItem;

/**
 * Default no-op binding: falls back to the cheap rule-based score untouched.
 * Bind a real multimodal-model-backed implementation once one is available.
 */
class NullVisionRelevanceAnalyzer implements VisionRelevanceAnalyzerInterface
{
    public function scoreRelevance(MediaAsset $asset, NewsItem $newsItem): float
    {
        return $asset->relevance_score ?? 0.5;
    }
}
