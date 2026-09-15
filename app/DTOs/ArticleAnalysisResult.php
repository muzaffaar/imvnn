<?php

namespace App\DTOs;

final class ArticleAnalysisResult
{
    public function __construct(
        public readonly bool $isAiRelated,
        public readonly ?string $title,
        public readonly ?string $content,
        public readonly string $analyzedBy, // provider name or 'heuristic' — kept for logging/debugging
        // 0-100 relevance/impact score for the channel's audience (Ministry of
        // Economy and Finance staff/leadership) — see AiArticleAnalyzer.
        public readonly int $score,
        // Whether this article should be ingested/published. Derived from
        // isAiRelated + score (see AiArticleAnalyzer), not a separate model
        // judgment — this is the field NewsIngestionService's topic gate
        // actually reads.
        public readonly bool $publish,
    ) {}
}
