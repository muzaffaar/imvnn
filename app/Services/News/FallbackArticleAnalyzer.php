<?php

namespace App\Services\News;

use App\DTOs\ArticleAnalysisResult;
use App\DTOs\ParsedArticle;
use App\DTOs\RawArticleCandidate;
use Illuminate\Support\Facades\Log;

/**
 * The binding NewsIngestionService actually depends on. Tries the configured
 * AI provider only when enabled with an API key, and unconditionally falls
 * back to the free heuristic path on ANY failure
 * — rate limit, quota exceeded, network error, malformed response — so a
 * provider outage degrades article quality/relevance-precision, but never
 * breaks news ingestion. Mirrors the same failure-handling principle used
 * throughout the media pipeline (see docs/MEDIA_ARCHITECTURE.md).
 */
class FallbackArticleAnalyzer implements ArticleAnalyzerInterface
{
    public function __construct(
        private readonly AiArticleAnalyzer $ai,
        private readonly HeuristicArticleAnalyzer $heuristic,
    ) {}

    public function analyze(RawArticleCandidate $candidate, ParsedArticle $heuristicParse, string $rawHtml): ArticleAnalysisResult
    {
        if ($this->aiEnabled()) {
            try {
                return $this->ai->analyze($candidate, $heuristicParse, $rawHtml);
            } catch (\Throwable $e) {
                Log::warning("[news-analysis] AI analysis failed for {$candidate->url}, falling back to heuristic: {$e->getMessage()}");
            }
        }

        return $this->heuristic->analyze($candidate, $heuristicParse, $rawHtml);
    }

    private function aiEnabled(): bool
    {
        return (bool) config('news_sources.ai.enabled')
            && (config('services.ai.provider') === 'openai-compatible' || filled(config('services.ai.api_key')));
    }
}
