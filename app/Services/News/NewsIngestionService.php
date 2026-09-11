<?php

namespace App\Services\News;

use App\DTOs\RawArticleCandidate;
use App\Jobs\Media\ExtractMediaJob;
use App\Models\NewsItem;
use App\Models\Source;
use App\Services\Http\BoundedHttpFetcher;
use App\Services\Http\BoundedHttpFetchException;
use App\Services\Media\Deduplication\UrlNormalizer;
use App\Support\Observability\PipelineLogger;
use Illuminate\Database\UniqueConstraintViolationException;

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
        private readonly TopicPolicy $topicPolicy,
    ) {}

    /** @return NewsItem|null null if the candidate was skipped (irrelevant, duplicate, or unfetchable) */
    public function ingest(Source $source, RawArticleCandidate $candidate): ?NewsItem
    {
        $canonicalUrl = $this->urlNormalizer->normalize($candidate->url);

        PipelineLogger::debug('news.ingestion_started', $this->candidateContext($source, $candidate));

        if (NewsItem::where('canonical_url', $canonicalUrl)->exists()) {
            $this->logSkip($source, $candidate, 'duplicate_canonical_url');

            return null; // already have this article, from this source or another
        }

        // Cheap prefilter before spending an HTTP fetch (or an AI call) —
        // matters most for html_crawl candidates, where this is anchor text,
        // our only signal before visiting the page.
        if (! $candidate->skipPrefilter
            && $candidate->prefilterText() !== ''
            && ! $this->prefilter->isRelevant($candidate->prefilterText())) {
            $this->logSkip($source, $candidate, 'prefilter_not_relevant');

            return null;
        }

        // RSS candidates carry a date from the feed, so most stale articles
        // can be dropped here — before spending an HTTP fetch and an AI
        // call on something that could never be published anyway. Crawled
        // candidates have no date yet and are re-checked after parsing.
        if ($candidate->publishedAt !== null && ! $this->freshness->isFresh($candidate->publishedAt)) {
            $this->logSkip($source, $candidate, 'feed_date_not_fresh', [
                'published_at' => $candidate->publishedAt->toDateString(),
            ]);

            return null;
        }

        $rawHtml = $candidate->rawHtml;
        $articleHtml = $rawHtml;

        if ($rawHtml === null) {
            $rawHtml = $this->fetchArticleHtml($source, $candidate->url);
            if ($rawHtml === null) {
                if (! $candidate->useFeedContentWhenArticleUnavailable || blank($candidate->summary)) {
                    $this->logSkip($source, $candidate, 'article_unavailable');

                    return null;
                }

                // A trusted feed may expose a valid, dated summary while its
                // article pages block non-browser HTTP clients. Analyze the
                // summary, but do not pretend this synthetic HTML was fetched
                // from the publisher.
                $rawHtml = $this->feedFallbackHtml($candidate);
                PipelineLogger::warning('news.feed_summary_fallback', $this->candidateContext($source, $candidate));
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
            $this->logSkip($source, $candidate, 'parsed_date_not_fresh', [
                'published_at' => $publishedAt?->toDateString(),
            ]);

            return null;
        }

        $analysis = $this->articleAnalyzer->analyze($candidate, $heuristicParse, $rawHtml);

        if (! $analysis->title) {
            $this->logSkip($source, $candidate, 'analysis_missing_title');

            return null; // nothing usable to publish under
        }

        // The model still judges relevance on every call, but its verdict only
        // decides anything while the topic filter is on. Honouring it anyway
        // would undo the bypass one stage late, after the fetch and the model
        // call had already been paid for.
        if ($this->topicPolicy->enabled() && ! $analysis->isAiRelated) {
            $this->logSkip($source, $candidate, 'analysis_not_ai_related');

            return null;
        }

        try {
            $newsItem = NewsItem::create([
                'source_id' => $source->id,
                'title' => $analysis->title,
                'url' => $candidate->url,
                'canonical_url' => $canonicalUrl,
                'raw_html' => $articleHtml,
                'source_payload' => $candidate->rssItem === null ? null : ['rss_item' => $candidate->rssItem],
                'content' => $analysis->content,
                'published_at' => $publishedAt,
            ]);

            PipelineLogger::info('news.ingested', $this->candidateContext($source, $candidate) + [
                'news_item_id' => $newsItem->id,
                'used_feed_summary_fallback' => $articleHtml === null && $candidate->rawHtml === null,
                'published_at' => $publishedAt?->toIso8601String(),
            ]);

            return $newsItem;
        } catch (UniqueConstraintViolationException) {
            $this->logSkip($source, $candidate, 'duplicate_race');

            return null; // lost a race with a concurrent fetch of the same article
        }
    }

    public function dispatchMediaExtraction(NewsItem $newsItem): void
    {
        ExtractMediaJob::dispatch($newsItem->id);

        PipelineLogger::info('media.extraction_queued', ['news_item_id' => $newsItem->id]);
    }

    private function fetchArticleHtml(Source $source, string $url): ?string
    {
        $limits = config('news_sources.limits');
        $options = is_array($source->fetch_options) ? $source->fetch_options : [];

        try {
            return $this->fetcher->downloadToMemory(
                $url,
                $limits['max_page_bytes'],
                $limits['download_timeout_seconds'],
                $limits['download_connect_timeout_seconds'],
                $this->fetcher->headersFromFetchOptions($options),
            );
        } catch (\Throwable $e) {
            $httpException = $e instanceof BoundedHttpFetchException ? $e : null;
            PipelineLogger::exception('news.article_fetch_failed', $e, [
                'source_id' => $source->id,
                'source_name' => $source->name,
                'article_url' => PipelineLogger::url($url),
                'http_status' => $httpException?->statusCode,
            ], 'warning');

            return null;
        }
    }

    private function feedFallbackHtml(RawArticleCandidate $candidate): string
    {
        $title = e($candidate->title ?? '');
        $summary = e($candidate->summary ?? '');

        return "<article><h1>{$title}</h1><p>{$summary}</p></article>";
    }

    /** @param array<string, mixed> $context */
    private function logSkip(Source $source, RawArticleCandidate $candidate, string $reason, array $context = []): void
    {
        if (! config('observability.candidate_skips')) {
            return;
        }

        PipelineLogger::info('news.ingestion_skipped', $this->candidateContext($source, $candidate) + ['reason' => $reason] + $context);
    }

    /** @return array<string, int|string|null> */
    private function candidateContext(Source $source, RawArticleCandidate $candidate): array
    {
        return [
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'article_url' => PipelineLogger::url($candidate->url),
        ];
    }
}
