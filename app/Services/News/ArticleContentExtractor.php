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

        return new ParsedArticle(
            title: $this->extractTitle($dom, $xpath),
            content: $this->extractContent($xpath),
            publishedAt: $this->extractPublishedAt($xpath),
        );
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

    private function extractPublishedAt(\DOMXPath $xpath): ?CarbonImmutable
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
            return CarbonImmutable::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
