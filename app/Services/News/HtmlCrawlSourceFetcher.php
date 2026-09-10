<?php

namespace App\Services\News;

use App\DTOs\RawArticleCandidate;
use App\Enums\SourceFetchType;
use App\Models\Source;
use App\Services\Http\BoundedHttpFetcher;
use Illuminate\Support\Collection;

/**
 * Scans a homepage/section page for links that look like articles, using
 * only URL-shape heuristics (same host, plausible slug, not an obvious
 * nav/tag/category/legal page) — this fetcher never tries to guess a title
 * or content from the listing page beyond the anchor text, since listing
 * markup varies wildly between sites. The actual article page is fetched
 * and parsed later, per candidate, by ArticlePageFetcher/ArticleContentExtractor
 * inside ProcessNewsCandidateJob — kept separate so a slow/broken article
 * page can't fail the whole source's discovery pass.
 */
class HtmlCrawlSourceFetcher implements NewsSourceFetcherInterface
{
    private const EXCLUDED_PATH_SEGMENTS = [
        'tag', 'tags', 'category', 'categories', 'topic', 'topics', 'author', 'authors',
        'about', 'contact', 'login', 'signin', 'signup', 'register', 'subscribe',
        'privacy', 'terms', 'search', 'page', 'wp-content', 'wp-json', 'feed', 'rss', 'atom',
        'account', 'cart', 'newsletter', 'advertise', 'careers', 'jobs',
    ];

    public function __construct(private readonly BoundedHttpFetcher $fetcher) {}

    public function supports(Source $source): bool
    {
        return $source->fetch_type === SourceFetchType::HtmlCrawl;
    }

    public function fetch(Source $source): Collection
    {
        $limits = config('news_sources.limits');

        $html = $this->fetcher->downloadToMemory(
            $source->source_url,
            $limits['max_page_bytes'],
            $limits['download_timeout_seconds'],
            $limits['download_connect_timeout_seconds'],
        );

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            return collect();
        }

        $host = parse_url($source->source_url, PHP_URL_HOST);
        $candidates = collect();

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href');
            $url = $this->resolveArticleUrl($href, $source->source_url, $host);

            if (! $url || $candidates->has($url)) {
                continue;
            }

            $text = trim(preg_replace('/\s+/', ' ', $anchor->textContent));

            $candidates->put($url, new RawArticleCandidate(url: $url, title: $text ?: null));
        }

        return $candidates->values()->take($limits['max_links_per_crawl']);
    }

    private function resolveArticleUrl(string $href, string $baseUrl, ?string $allowedHost): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
            return null;
        }

        $resolved = $this->resolve($href, $baseUrl);
        if (! $resolved) {
            return null;
        }

        $parts = parse_url($resolved);
        if (empty($parts['host']) || $parts['host'] !== $allowedHost) {
            return null; // stay on the source's own domain — no chasing outbound/syndication links
        }

        $path = trim($parts['path'] ?? '', '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), self::EXCLUDED_PATH_SEGMENTS, true)) {
                return null;
            }
        }

        // A plausible article slug: reasonably deep path, or a long hyphenated
        // last segment (.../2026/09/some-article-title, .../some-article-title).
        $lastSegment = end($segments);
        $looksLikeSlug = count($segments) >= 2 || substr_count($lastSegment, '-') >= 2;

        if (! $looksLikeSlug) {
            return null;
        }

        return strtok($resolved, '#'); // drop fragments
    }

    private function resolve(string $url, string $baseUrl): ?string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $base = parse_url($baseUrl);
        if (! $base || empty($base['host'])) {
            return null;
        }

        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];

        if (str_starts_with($url, '//')) {
            return "{$scheme}:{$url}";
        }

        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        return null; // relative-to-current-path hrefs on a listing page are rare enough not to bother resolving
    }
}
