<?php

namespace App\Services\News;

use App\DTOs\ArticleAnalysisResult;
use App\DTOs\ParsedArticle;
use App\DTOs\RawArticleCandidate;
use Illuminate\Support\Facades\Log;

/**
 * The binding NewsIngestionService actually depends on. Tries Gemini only
 * when configured (a real API key present and news_sources.gemini.enabled),
 * and unconditionally falls back to the free heuristic path on ANY failure
 * — rate limit, quota exceeded, network error, malformed response — so a
 * Gemini outage degrades article quality/relevance-precision, but never
 * breaks news ingestion. Mirrors the same failure-handling principle used
 * throughout the media pipeline (see docs/MEDIA_ARCHITECTURE.md).
 */
class FallbackArticleAnalyzer implements ArticleAnalyzerInterface
{
    public function __construct(
        private readonly GeminiArticleAnalyzer $gemini,
        private readonly HeuristicArticleAnalyzer $heuristic,
    ) {}

    public function analyze(RawArticleCandidate $candidate, ParsedArticle $heuristicParse, string $rawHtml): ArticleAnalysisResult
    {
        if ($this->geminiEnabled()) {
            try {
                return $this->gemini->analyze($candidate, $heuristicParse, $rawHtml);
            } catch (\Throwable $e) {
                Log::warning("[news-analysis] Gemini analysis failed for {$candidate->url}, falling back to heuristic: {$e->getMessage()}");
            }
        }

        return $this->heuristic->analyze($candidate, $heuristicParse, $rawHtml);
    }

    private function geminiEnabled(): bool
    {
        return (bool) config('news_sources.gemini.enabled') && filled(config('services.gemini.api_key'));
    }
}
