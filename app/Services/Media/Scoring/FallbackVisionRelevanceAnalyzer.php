<?php

namespace App\Services\Media\Scoring;

use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Support\Observability\PipelineLogger;

/**
 * The binding RelevanceAnalysisService actually depends on. Tries the real
 * vision-model call only when enabled with an API key, and unconditionally
 * falls back to the cheap rule-based score on ANY failure — network error,
 * rate limit, quota, malformed response, oversized/unreadable image — so a
 * provider outage degrades image-selection precision but never breaks the
 * media pipeline. Mirrors FallbackArticleAnalyzer / FallbackCaptionComposer.
 */
class FallbackVisionRelevanceAnalyzer implements VisionRelevanceAnalyzerInterface
{
    public function __construct(
        private readonly AiVisionRelevanceAnalyzer $ai,
        private readonly NullVisionRelevanceAnalyzer $null,
    ) {}

    public function scoreRelevance(MediaAsset $asset, NewsItem $newsItem): float
    {
        if ($this->aiEnabled()) {
            try {
                return $this->ai->scoreRelevance($asset, $newsItem);
            } catch (\Throwable $e) {
                PipelineLogger::exception('media.vision_relevance_fallback', $e, [
                    'media_asset_id' => $asset->id,
                    'news_item_id' => $newsItem->id,
                ], 'warning');
            }
        }

        return $this->null->scoreRelevance($asset, $newsItem);
    }

    private function aiEnabled(): bool
    {
        return (bool) config('media.relevance.ai_enabled')
            && (config('services.ai.provider') === 'openai-compatible' || filled(config('services.ai.api_key')));
    }
}
