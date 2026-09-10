<?php

namespace App\Services\News;

use App\DTOs\ArticleAnalysisResult;
use App\DTOs\ParsedArticle;
use App\DTOs\RawArticleCandidate;

/**
 * The free, deterministic, always-succeeds default: reuses whatever
 * ArticleContentExtractor already parsed, and the keyword AiRelevanceFilter
 * for the relevance judgment. Never throws — this is also the fallback
 * FallbackArticleAnalyzer lands on when the AI provider is disabled or fails.
 */
class HeuristicArticleAnalyzer implements ArticleAnalyzerInterface
{
    public function __construct(private readonly AiRelevanceFilter $aiFilter) {}

    public function analyze(RawArticleCandidate $candidate, ParsedArticle $heuristicParse, string $rawHtml): ArticleAnalysisResult
    {
        $title = $heuristicParse->title ?? $candidate->title;
        $content = $heuristicParse->content ?? $candidate->summary;

        $isAiRelated = $this->aiFilter->isRelevant(($title ?? '').' '.($content ?? ''));

        return new ArticleAnalysisResult($isAiRelated, $title, $content, analyzedBy: 'heuristic');
    }
}
