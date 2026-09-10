<?php

namespace App\Services\News;

use App\DTOs\RawArticleCandidate;
use App\Jobs\Media\ExtractMediaJob;
use App\Models\NewsItem;
use App\Models\Source;
use App\Services\Http\BoundedHttpFetcher;
use App\Services\Media\Deduplication\UrlNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
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
        private readonly ArticleAnalyzerInterface $articleAnalyzer,
        private readonly AiRelevanceFilter $prefilter,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly FreshnessPolicy $freshness,
    ) {}

    /** @return NewsItem|null null if the candidate was skipped (irrelevant, duplicate, or unfetchable) */
    public function ingest(Source $source, RawArticleCandidate $candidate): ?NewsItem
    {
        $canonicalUrl = $this->urlNormalizer->normalize($candidate->url);

        if (NewsItem::where('canonical_url', $canonicalUrl)->exists()) {
            return null; // already have this article, from this source or another
        }

        // Cheap prefilter before spending an HTTP fetch (or an AI call) —
        // matters most for html_crawl candidates, where this is anchor text,
        // our only signal before visiting the page.
        if (! $candidate->skipPrefilter
            && $candidate->prefilterText() !== ''
            && ! $this->prefilter->isRelevant($candidate->prefilterText())) {
            return null;
        }

        // RSS candidates carry a date from the feed, so most stale articles
        // can be dropped here — before spending an HTTP fetch and an AI
        // call on something that could never be published anyway. Crawled
        // candidates have no date yet and are re-checked after parsing.
        if ($candidate->publishedAt !== null && ! $this->freshness->isFresh($candidate->publishedAt)) {
            return null;
        }

        $rawHtml = $candidate->rawHtml;
        $articleHtml = $rawHtml;

        if ($rawHtml === null) {
            $rawHtml = $this->fetchArticleHtml($candidate->url);
            if ($rawHtml === null) {
                if (! $candidate->useFeedContentWhenArticleUnavailable || blank($candidate->summary)) {
                    return null;
                }

                // A trusted feed may expose a valid, dated summary while its
                // article pages block non-browser HTTP clients. Analyze the
                // summary, but do not pretend this synthetic HTML was fetched
                // from the publisher.
                $rawHtml = $this->feedFallbackHtml($candidate);
            } else {
                $articleHtml = $rawHtml;
            }
        }

        // Date extraction is cheap/deterministic and always worth running,
        // regardless of which analyzer (provider or heuristic) ends up
        // producing the title/content/relevance verdict below.
        $heuristicParse = $this->contentExtractor->extract($rawHtml);
        $publishedAt = $heuristicParse->publishedAt ?? $candidate->publishedAt;

        // Authoritative freshness gate: for crawled candidates this is the
        // first point a date exists at all, and it still runs before the
        // AI call.
        if (! $this->freshness->isFresh($publishedAt)) {
            Log::info("[news-ingestion] skipped as not from today ({$candidate->url}), published_at=".($publishedAt?->toDateString() ?? 'unknown'));

            return null;
        }

        $analysis = $this->articleAnalyzer->analyze($candidate, $heuristicParse, $rawHtml);

        if (! $analysis->title) {
            return null; // nothing usable to publish under
        }

        if (! $analysis->isAiRelated) {
            return null;
        }

        try {
            return NewsItem::create([
                'source_id' => $source->id,
                'title' => $analysis->title,
                'url' => $candidate->url,
                'canonical_url' => $canonicalUrl,
                'raw_html' => $articleHtml,
                'source_payload' => $candidate->rssItem === null ? null : ['rss_item' => $candidate->rssItem],
                'content' => $analysis->content,
                'published_at' => $publishedAt,
            ]);
        } catch (UniqueConstraintViolationException) {
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

    private function feedFallbackHtml(RawArticleCandidate $candidate): string
    {
        $title = e($candidate->title ?? '');
        $summary = e($candidate->summary ?? '');

        return "<article><h1>{$title}</h1><p>{$summary}</p></article>";
    }
}
