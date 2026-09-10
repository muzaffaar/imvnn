<?php

namespace App\Services\News;

use App\DTOs\RawArticleCandidate;
use App\Enums\SourceFetchType;
use App\Models\Source;
use App\Services\Http\BoundedHttpFetcher;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Discovers article links from a server-rendered homepage or section page.
 *
 * Generic URL-shape heuristics remain the safe default, while fetch_options
 * lets an operator tune a difficult source without adding a new PHP class:
 * allowed_hosts, include_url_patterns, exclude_url_patterns,
 * excluded_path_segments, article_link_xpath, next_page_xpath, max_pages,
 * max_links and skip_prefilter. XPath selectors must select <a> elements.
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
        $options = is_array($source->fetch_options) ? $source->fetch_options : [];
        $maxLinks = max(1, min((int) ($options['max_links'] ?? $limits['max_links_per_crawl']), 100));
        $maxPages = max(1, min((int) ($options['max_pages'] ?? 1), 10));
        $allowedHosts = $this->allowedHosts($source, $options);
        $sourceFirstSegment = $this->firstPathSegment($source->source_url);

        $pages = [$source->source_url];
        $visitedPages = [];
        $candidates = collect();

        while ($pages !== [] && count($visitedPages) < $maxPages && $candidates->count() < $maxLinks) {
            $pageUrl = array_shift($pages);
            if (! is_string($pageUrl) || isset($visitedPages[$pageUrl])) {
                continue;
            }
            $visitedPages[$pageUrl] = true;

            $html = $this->fetcher->downloadToMemory(
                $pageUrl,
                $limits['max_page_bytes'],
                $limits['download_timeout_seconds'],
                $limits['download_connect_timeout_seconds'],
            );

            $dom = $this->loadDom($html);
            if (! $dom) {
                continue;
            }

            foreach ($this->articleAnchors($dom, $options) as $anchor) {
                $url = $this->resolveArticleUrl(
                    $anchor->getAttribute('href'),
                    $pageUrl,
                    $allowedHosts,
                    $sourceFirstSegment,
                    $options,
                );

                if (! $url || $candidates->has($url)) {
                    continue;
                }

                $text = trim(preg_replace('/\s+/', ' ', $anchor->textContent) ?? '');
                $candidates->put($url, new RawArticleCandidate(
                    url: $url,
                    title: $text ?: null,
                    skipPrefilter: (bool) ($options['skip_prefilter'] ?? false),
                ));

                if ($candidates->count() >= $maxLinks) {
                    break;
                }
            }

            if (count($visitedPages) < $maxPages && $candidates->count() < $maxLinks) {
                $next = $this->nextPageUrl($dom, $pageUrl, $allowedHosts, $options);
                if ($next && ! isset($visitedPages[$next])) {
                    $pages[] = $next;
                }
            }
        }

        return $candidates->values();
    }

    private function loadDom(string $html): ?\DOMDocument
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return $loaded ? $dom : null;
    }

    /** @param array<string, mixed> $options @return iterable<\DOMElement> */
    private function articleAnchors(\DOMDocument $dom, array $options): iterable
    {
        $xpath = new \DOMXPath($dom);
        $expression = $options['article_link_xpath'] ?? '//a[@href]';

        if (! is_string($expression)) {
            return [];
        }

        try {
            $nodes = $xpath->query($expression);
        } catch (\Throwable) {
            return [];
        }

        if (! $nodes) {
            return [];
        }

        return array_filter(iterator_to_array($nodes), fn ($node) => $node instanceof \DOMElement && strtolower($node->tagName) === 'a');
    }

    /** @param list<string> $allowedHosts @param array<string, mixed> $options */
    private function resolveArticleUrl(string $href, string $baseUrl, array $allowedHosts, ?string $sourceFirstSegment, array $options): ?string
    {
        $resolved = $this->resolve($href, $baseUrl);
        if (! $resolved) {
            return null;
        }

        $parts = parse_url($resolved);
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || ! in_array($host, $allowedHosts, true)) {
            return null;
        }

        $path = trim($parts['path'] ?? '', '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        $excludedSegments = array_merge(self::EXCLUDED_PATH_SEGMENTS, $this->stringList($options['excluded_path_segments'] ?? []));
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), $excludedSegments, true)) {
                return null;
            }
        }

        if ($this->matchesAnyPattern($resolved, $this->stringList($options['exclude_url_patterns'] ?? [], lowercase: false))) {
            return null;
        }

        $includePatterns = $this->stringList($options['include_url_patterns'] ?? [], lowercase: false);
        if ($includePatterns !== [] && ! $this->matchesAnyPattern($resolved, $includePatterns)) {
            return null;
        }

        if (! $this->looksLikeArticle($segments, $sourceFirstSegment)) {
            return null;
        }

        return $resolved;
    }

    /** @param list<string> $allowedHosts @param array<string, mixed> $options */
    private function nextPageUrl(\DOMDocument $dom, string $baseUrl, array $allowedHosts, array $options): ?string
    {
        $expression = $options['next_page_xpath'] ?? null;
        if (! is_string($expression) || $expression === '') {
            return null;
        }

        try {
            $nodes = (new \DOMXPath($dom))->query($expression);
        } catch (\Throwable) {
            return null;
        }

        if (! $nodes) {
            return null;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement || strtolower($node->tagName) !== 'a') {
                continue;
            }

            $url = $this->resolve($node->getAttribute('href'), $baseUrl);
            $host = strtolower(parse_url($url ?? '', PHP_URL_HOST) ?? '');
            if ($url && in_array($host, $allowedHosts, true)) {
                return $url;
            }
        }

        return null;
    }

    private function looksLikeArticle(array $segments, ?string $sourceFirstSegment): bool
    {
        $lastIndex = count($segments) - 1;
        $lastSegment = $segments[$lastIndex];

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

        return $depth === 1 && substr_count($segments[0], '-') >= 3;
    }

    /** @param array<string, mixed> $options @return list<string> */
    private function allowedHosts(Source $source, array $options): array
    {
        $configured = $this->stringList($options['allowed_hosts'] ?? []);
        $default = strtolower(parse_url($source->source_url, PHP_URL_HOST) ?? '');

        return array_values(array_unique(array_filter([...$configured, $default])));
    }

    /** @return list<string> */
    private function stringList(mixed $value, bool $lowercase = true): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? ($lowercase ? strtolower(trim($item)) : trim($item)) : null,
            $value,
        )));
    }

    /** @param list<string> $patterns */
    private function matchesAnyPattern(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    private function firstPathSegment(?string $url): ?string
    {
        $path = trim(parse_url($url ?? '', PHP_URL_PATH) ?? '', '/');

        return $path !== '' ? strtolower(explode('/', $path)[0]) : null;
    }

    private function resolve(string $url, string $baseUrl): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        try {
            $resolved = UriResolver::resolve(new Uri($baseUrl), new Uri($url));
        } catch (\Throwable) {
            return null;
        }

        if (! in_array(strtolower($resolved->getScheme()), ['http', 'https'], true) || $resolved->getHost() === '') {
            return null;
        }

        return (string) $resolved->withFragment('');
    }
}
