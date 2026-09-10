<?php

namespace App\Services\News;

use App\DTOs\RawArticleCandidate;
use App\Jobs\Media\ExtractMediaJob;
use App\Models\NewsItem;
use App\Models\Source;
use App\Services\Http\BoundedHttpFetcher;
use App\Services\Media\Deduplication\UrlNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * Turns one RawArticleCandidate into a NewsItem (or decides not to), and
 * hands off to the already-built media pipeline. This is the seam between
 * "news fetching" and "media processing" — see docs/MEDIA_ARCHITECTURE.md,
 * which picks up from exactly the NewsItem this creates.
 */
class NewsIngestionService
{
    public function __construct(
        private readonly BoundedHttpFetcher $fetcher,
        private readonly ArticleContentExtractor $contentExtractor,
        private readonly AiRelevanceFilter $aiFilter,
        private readonly UrlNormalizer $urlNormalizer,
    ) {}

    /** @return NewsItem|null null if the candidate was skipped (irrelevant, duplicate, or unfetchable) */
    public function ingest(Source $source, RawArticleCandidate $candidate): ?NewsItem
    {
        $canonicalUrl = $this->urlNormalizer->normalize($candidate->url);

        if (NewsItem::where('canonical_url', $canonicalUrl)->exists()) {
            return null; // already have this article, from this source or another
        }

        // Cheap prefilter before spending an HTTP fetch — matters most for
        // html_crawl candidates, where this is anchor text, our only signal
        // before visiting the page.
        if ($candidate->prefilterText() !== '' && ! $this->aiFilter->isRelevant($candidate->prefilterText())) {
            return null;
        }

        $rawHtml = $candidate->rawHtml;
        $title = $candidate->title;
        $content = $candidate->summary;
        $publishedAt = $candidate->publishedAt;

        if ($rawHtml === null) {
            $rawHtml = $this->fetchArticleHtml($candidate->url);
            if ($rawHtml === null) {
                return null;
            }

            $parsed = $this->contentExtractor->extract($rawHtml);
            $title = $parsed->title ?? $title;
            $content = $parsed->content ?? $content;
            $publishedAt = $parsed->publishedAt ?? $publishedAt;
        }

        if (! $title) {
            return null; // nothing usable to publish under
        }

        // Authoritative filter: title + whatever content we actually have,
        // not just the weak prefilter signal.
        if (! $this->aiFilter->isRelevant($title.' '.($content ?? ''))) {
            return null;
        }

        try {
            return NewsItem::create([
                'source_id' => $source->id,
                'title' => $title,
                'url' => $candidate->url,
                'canonical_url' => $canonicalUrl,
                'raw_html' => $rawHtml,
                'content' => $content,
                'published_at' => $publishedAt,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return null; // lost a race with a concurrent fetch of the same article
        }
    }

    public function dispatchMediaExtraction(NewsItem $newsItem): void
    {
        ExtractMediaJob::dispatch($newsItem->id);
    }

    private function fetchArticleHtml(string $url): ?string
    {
        $limits = config('news_sources.limits');

        try {
            return $this->fetcher->downloadToMemory(
                $url,
                $limits['max_page_bytes'],
                $limits['download_timeout_seconds'],
                $limits['download_connect_timeout_seconds'],
            );
        } catch (\Throwable $e) {
            Log::warning("[news-ingestion] failed to fetch article page {$url}: {$e->getMessage()}");

            return null;
        }
    }
}
