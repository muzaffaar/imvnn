<?php

namespace App\DTOs;

final class ArticleAnalysisResult
{
    public function __construct(
        public readonly bool $isAiRelated,
        public readonly ?string $title,
        public readonly ?string $content,
        public readonly string $analyzedBy, // provider name or 'heuristic' — kept for logging/debugging
    ) {}
}
