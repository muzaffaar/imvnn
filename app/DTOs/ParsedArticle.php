<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

final class ParsedArticle
{
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $content,
        public readonly ?CarbonImmutable $publishedAt,
    ) {}
}
