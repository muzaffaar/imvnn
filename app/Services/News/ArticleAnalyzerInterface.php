<?php

namespace App\Services\News;

use App\DTOs\ArticleAnalysisResult;
use App\DTOs\ParsedArticle;
use App\DTOs\RawArticleCandidate;

/**
 * Decides an article's final title/content and AI-relevance. Two
 * implementations: HeuristicArticleAnalyzer (free, deterministic — the
 * original ArticleContentExtractor + AiRelevanceFilter) and
 * AiArticleAnalyzer (one structured-output provider call does both).
 * NewsIngestionService
 * depends on FallbackArticleAnalyzer, which tries the configured provider
 * and falls back to the heuristic on any failure — see
 * docs/NEWS_FETCHING.md "AI analysis".
 */
interface ArticleAnalyzerInterface
{
    public function analyze(RawArticleCandidate $candidate, ParsedArticle $heuristicParse, string $rawHtml): ArticleAnalysisResult;
}
