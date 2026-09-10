<?php

namespace App\Services\News;

use App\DTOs\ParsedArticle;
use Carbon\CarbonImmutable;

/**
 * Heuristic article-page parsing: not a full Readability port, just enough
 * to get a usable title/body/publish-date out of an arbitrary news site.
 * Deliberately prefers semantic markup (<article>, meta tags, <time>) over
 * guessing, and falls back to the largest paragraph cluster only when those
 * aren't present.
 */
class ArticleContentExtractor
{
    public function extract(string $html): ParsedArticle
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            return new ParsedArticle(null, null, null);
        }

        $xpath = new \DOMXPath($dom);
        $this->stripNonContentNodes($xpath);

        $title = $this->extractTitle($dom, $xpath);

        return new ParsedArticle(
            title: $title,
            content: $this->extractContent($xpath),
            publishedAt: $this->extractPublishedAt($xpath, $title),
        );
    }

    /**
     * `textContent` happily returns the contents of <script>, and modern
     * frameworks embed the entire page as serialized JSON there — anthropic.com
     * ships a Next.js hydration payload in which the article's own title
     * appears again around character 139,000. Any scan over body text (dates,
     * paragraph density) otherwise wanders into that blob and matches the
     * wrong thing entirely.
     */
    private function stripNonContentNodes(\DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//script | //style | //noscript | //template');

        if (! $nodes) {
            return;
        }

        foreach (iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function extractTitle(\DOMDocument $dom, \DOMXPath $xpath): ?string
    {
        $ogTitle = $xpath->query('//meta[@property="og:title"]/@content')->item(0)?->nodeValue;
        if ($ogTitle && trim($ogTitle) !== '') {
            return trim($ogTitle);
        }

        $titleTag = $dom->getElementsByTagName('title')->item(0)?->textContent;
        if ($titleTag && trim($titleTag) !== '') {
            return trim($titleTag);
        }

        $h1 = $dom->getElementsByTagName('h1')->item(0)?->textContent;

        return $h1 ? trim($h1) : null;
    }

    private function extractPublishedAt(\DOMXPath $xpath, ?string $title = null): ?CarbonImmutable
    {
        $metaNames = ['article:published_time', 'og:article:published_time', 'publish-date', 'publishdate', 'date'];

        foreach ($metaNames as $name) {
            $value = $xpath->query("//meta[@property=\"{$name}\" or @name=\"{$name}\"]/@content")->item(0)?->nodeValue;
            if ($value && $parsed = $this->tryParseDate($value)) {
                return $parsed;
            }
        }

        $timeDatetime = $xpath->query('//time/@datetime')->item(0)?->nodeValue;
        if ($timeDatetime && $parsed = $this->tryParseDate($timeDatetime)) {
            return $parsed;
        }

        return $this->extractDateFromVisibleText($xpath, $title);
    }

    /**
     * Last resort: some sites publish the date as plain text and expose it
     * nowhere machine-readable — anthropic.com has no date meta tag, no
     * <time> element and no JSON-LD, just "Aug 14, 2026" rendered directly
     * after the headline. Without this, such a source yields no date at all,
     * and a date filter would silently drop every one of its articles.
     *
     * Takes the first date near the top of the page (preferring one just
     * after the title, where publishers overwhelmingly put it) rather than
     * any date anywhere — footers and "related articles" cards carry other
     * dates that would otherwise win.
     */
    private function extractDateFromVisibleText(\DOMXPath $xpath, ?string $title): ?CarbonImmutable
    {
        $body = $xpath->query('//body')?->item(0);
        if (! $body instanceof \DOMElement) {
            return null;
        }

        $text = preg_replace('/\s+/', ' ', $body->textContent) ?? '';

        // Start scanning just after the headline when we can find it.
        if ($title) {
            $titlePosition = mb_stripos($text, mb_substr($title, 0, 60));
            if ($titlePosition !== false) {
                $text = mb_substr($text, $titlePosition);
            }
        }

        $text = mb_substr($text, 0, 2000);

        // No \b before the month name on purpose: textContent concatenates
        // adjacent elements without whitespace, so the date arrives glued to
        // the headline ("...watermark worksAug 14, 2026Future Claude models"),
        // and a word-boundary anchor never matches. The surrounding structure
        // (day, optional comma, 4-digit year) is specific enough on its own;
        // (?!\d) just stops a longer number being clipped into a false year.
        $patterns = [
            '/(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.? \d{1,2},? \d{4}(?!\d)/',
            '/(?<!\d)\d{1,2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.? \d{4}(?!\d)/',
            '/(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) && $parsed = $this->tryParseDate($match[0])) {
                return $parsed;
            }
        }

        return null;
    }

    private function extractContent(\DOMXPath $xpath): ?string
    {
        $articleNode = $xpath->query('//article')?->item(0);
        $container = $articleNode instanceof \DOMElement ? $articleNode : $this->largestParagraphCluster($xpath);

        if (! $container) {
            return null;
        }

        $paragraphs = [];
        foreach ($container->getElementsByTagName('p') as $p) {
            $text = trim(preg_replace('/\s+/', ' ', $p->textContent));
            if (mb_strlen($text) > 40) { // skip short boilerplate/caption-like paragraphs
                $paragraphs[] = $text;
            }
        }

        $content = implode("\n\n", $paragraphs);

        return $content !== '' ? mb_substr($content, 0, 8000) : null;
    }

    /**
     * Fallback when there's no <article> tag: the container with the highest
     * paragraph-text density (text length per descendant element), not just
     * the most raw text — since XPath's `.//p` matches at any depth, a naive
     * "most cumulative text" pick would nearly always win on some outer
     * wrapper (body, layout div) that also happens to contain nav/footer/
     * sidebar text, rather than the actual article body.
     */
    private function largestParagraphCluster(\DOMXPath $xpath): ?\DOMElement
    {
        $candidates = $xpath->query('//div[.//p] | //section[.//p] | //main[.//p]');
        $best = null;
        $bestDensity = 0.0;

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof \DOMElement) {
                continue;
            }

            $textLength = 0;
            foreach ($candidate->getElementsByTagName('p') as $p) {
                $textLength += mb_strlen($p->textContent);
            }

            if ($textLength <= 200) {
                continue;
            }

            $elementCount = $candidate->getElementsByTagName('*')->length;
            $density = $textLength / (1 + $elementCount);

            if ($density > $bestDensity) {
                $bestDensity = $density;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function tryParseDate(string $value): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }

        // Guards the plain-text fallback especially: a stray number sequence
        // can parse into something absurd, and a bogus date is worse than no
        // date once a freshness filter depends on it.
        if ($parsed->year < 2000 || $parsed->isAfter(CarbonImmutable::now()->addDays(2))) {
            return null;
        }

        return $parsed;
    }
}
