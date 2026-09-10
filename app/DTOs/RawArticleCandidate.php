<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * One discovered article, before we've committed to fetching its full page
 * (html_crawl candidates) or before parsing (rss candidates already carry a
 * summary). See NewsSourceFetcherInterface / ProcessNewsCandidateJob.
 */
final class RawArticleCandidate
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $summary = null,
        public readonly ?CarbonImmutable $publishedAt = null,
        public readonly ?string $rawHtml = null,
        /** Normalized shape RssMediaExtractor expects — see ExtractionContext::$rssItem. */
        public readonly ?array $rssItem = null,
    ) {}

    /** Cheap text used for the pre-fetch AI relevance prefilter. */
    public function prefilterText(): string
    {
        return trim(($this->title ?? '').' '.($this->summary ?? ''));
    }
}
