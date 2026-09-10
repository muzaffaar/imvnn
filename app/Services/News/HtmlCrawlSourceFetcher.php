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
 * and parsed later, per candidate, by ArticleContentExtractor inside
 * ProcessNewsCandidateJob — kept separate so a slow/broken article page
 * can't fail the whole source's discovery pass.
 *
 * Requires positive evidence a link is an article, not just the absence of
 * obvious junk — every real news/blog site also links to dozens of nav/
 * taxonomy/product pages from every single page (see
 * docs/NEWS_FETCHING.md "HTML crawl heuristic" for the real sites this was
 * tuned against and why a weaker "any multi-segment path" rule let those
 * nav links drown out real articles).
 */
class HtmlCrawlSourceFetcher implements NewsSourceFetcherInterface
{
    private const EXCLUDED_PATH_SEGMENTS = [
        'tag', 'tags', 'category', 'categories', 'label', 'labels', 'topic', 'topics', 'author', 'authors',
        'about', 'contact', 'login', 'signin', 'signup', 'register', 'subscribe',
        'privacy', 'terms', 'terms-of-service', 'search', 'page', 'wp-content', 'wp-json', 'feed', 'rss', 'atom',
        'account', 'cart', 'newsletter', 'advertise', 'careers', 'jobs',
        'join', 'inference', 'department', 'departments', 'centers-labs-programs', 'clp',
        'research-areas', 'pricing', 'products', 'industry', 'discord', 'community',
        'magazines', 'supertopic',
    ];

    /**
     * First path segment values that, on their own (plus at least one more
     * segment after them), are strong evidence of an individual post rather
     * than a section index — e.g. "/news/some-post", "/blog/author/some-post".
     */
    private const KNOWN_CONTENT_SECTIONS = [
        'news', 'blog', 'blogs', 'article', 'articles', 'press', 'posts', 'post',
        'stories', 'story', 'features', 'insights',
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
        $sourceFirstSegment = $this->firstPathSegment($source->source_url);
        $candidates = collect();

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href');
            $url = $this->resolveArticleUrl($href, $source->source_url, $host, $sourceFirstSegment);

            if (! $url || $candidates->has($url)) {
                continue;
            }

            $text = trim(preg_replace('/\s+/', ' ', $anchor->textContent));

            $candidates->put($url, new RawArticleCandidate(url: $url, title: $text ?: null));
        }

        return $candidates->values()->take($limits['max_links_per_crawl']);
    }

    private function resolveArticleUrl(string $href, string $baseUrl, ?string $allowedHost, ?string $sourceFirstSegment): ?string
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

        if (! $this->looksLikeArticle($segments, $sourceFirstSegment)) {
            return null;
        }

        return strtok($resolved, '#'); // drop fragments
    }

    /**
     * Positive evidence, any one of which is enough:
     *   - a dated path with something after the year (.../2026/some-article,
     *     .../2026/09/some-article) — a year segment that's the LAST segment
     *     (.../blog/2026) is a year-archive index, not an article, and is
     *     deliberately excluded (confirmed against research.google/blog,
     *     which links every year back to /blog/2020 .. /blog/2026)
     *   - starts with a known content-section word and goes at least one level deeper
     *     (.../news/some-post, .../blog/author/some-post)
     *   - starts with the same first segment as the source page we were told to
     *     crawl, going deeper than that page itself (source-specific, since not
     *     every site's section word is in KNOWN_CONTENT_SECTIONS)
     *   - a single root-level segment that's a long, heavily-hyphenated slug
     *     (some sites place featured posts at the bare root, e.g. anthropic.com)
     */
    private function looksLikeArticle(array $segments, ?string $sourceFirstSegment): bool
    {
        $lastIndex = count($segments) - 1;
        $lastSegment = $segments[$lastIndex];

        // A purely numeric final segment is a year/page-number archive or
        // pagination index (.../blog/2026, .../blog/page/2), never an
        // individual article slug — reject outright regardless of any
        // positive signal below (confirmed against research.google/blog,
        // which links every year archive from its own /blog listing).
        if (preg_match('/^\d+$/', $lastSegment)) {
            return false;
        }

        foreach ($segments as $index => $segment) {
            if ($index < $lastIndex && preg_match('/^(19|20)\d{2}$/', $segment)) {
                return true;
            }
        }

        $first = strtolower($segments[0]);
        $depth = count($segments);

        if (in_array($first, self::KNOWN_CONTENT_SECTIONS, true) && $depth >= 2) {
            return true;
        }

        if ($sourceFirstSegment && $first === $sourceFirstSegment && $depth >= 2) {
            return true;
        }

        if ($depth === 1 && substr_count($segments[0], '-') >= 3) {
            return true;
        }

        return false;
    }

    private function firstPathSegment(string $url): ?string
    {
        $path = trim(parse_url($url, PHP_URL_PATH) ?? '', '/');

        return $path !== '' ? strtolower(explode('/', $path)[0]) : null;
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
