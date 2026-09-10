<?php

namespace App\Services\News;

use App\DTOs\ArticleAnalysisResult;
use App\DTOs\ParsedArticle;
use App\DTOs\RawArticleCandidate;

/**
 * Decides an article's final title/content and AI-relevance. Two
 * implementations: HeuristicArticleAnalyzer (free, deterministic — the
 * original ArticleContentExtractor + AiRelevanceFilter) and
 * GeminiArticleAnalyzer (one Gemini call does both). NewsIngestionService
 * depends on FallbackArticleAnalyzer, which tries Gemini (if configured)
 * and falls back to the heuristic on any failure — see
 * docs/NEWS_FETCHING.md "Gemini analysis".
 */
interface ArticleAnalyzerInterface
{
    public function analyze(RawArticleCandidate $candidate, ParsedArticle $heuristicParse, string $rawHtml): ArticleAnalysisResult;
}
