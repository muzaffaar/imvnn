<?php

namespace App\DTOs;

/**
 * Everything a MediaExtractor might need, gathered once per news item so
 * extractors stay pure functions of (context) -> Collection<ExtractedMedia>.
 */
final class ExtractionContext
{
    public function __construct(
        public readonly string $newsItemId,
        public readonly string $baseUrl,       // for resolving relative <img src="/foo.jpg">
        public readonly ?string $title = null,  // used later by relevance scoring, kept here for convenience
        public readonly ?string $html = null,
        public readonly ?array $rssItem = null, // normalized RSS/Atom item fields
        public readonly ?array $apiPayload = null,
    ) {}
}
