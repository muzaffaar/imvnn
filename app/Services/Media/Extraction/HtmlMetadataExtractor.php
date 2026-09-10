<?php

namespace App\Services\Media\Extraction;

use App\DTOs\ExtractedMedia;
use App\DTOs\ExtractionContext;
use App\Enums\MediaType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Extracts og:image / og:video / Twitter Card / Schema.org JSON-LD media hints
 * from an article's <head>. These are treated as the strongest featured-image
 * signal (see ExtractedMedia::$isFeaturedHint) because the publisher chose them
 * specifically to represent the article.
 */
class HtmlMetadataExtractor implements MediaExtractorInterface
{
    use ResolvesUrls;

    public function supports(ExtractionContext $context): bool
    {
        return filled($context->html);
    }

    public function extract(ExtractionContext $context): Collection
    {
        $results = collect();
        $dom = $this->loadDom($context->html);

        if (! $dom) {
            return $results;
        }

        $xpath = new \DOMXPath($dom);
        $position = 0;

        foreach ($this->extractOpenGraph($xpath, $context, $position) as $item) {
            $results->push($item);
            $position++;
        }

        foreach ($this->extractTwitterCard($xpath, $context, $position) as $item) {
            $results->push($item);
            $position++;
        }

        foreach ($this->extractJsonLd($dom, $context, $position) as $item) {
            $results->push($item);
            $position++;
        }

        return $results->unique(fn (ExtractedMedia $m) => $m->fingerprint())->values();
    }

    private function loadDom(string $html): ?\DOMDocument
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return $loaded ? $dom : null;
    }

    /** @return list<ExtractedMedia> */
    private function extractOpenGraph(\DOMXPath $xpath, ExtractionContext $context, int $startPosition): array
    {
        $out = [];
        $nodes = $xpath->query('//meta[starts-with(@property, "og:image") or starts-with(@property, "og:video")]');

        $pendingImage = null;
        $pendingVideo = null;

        foreach ($nodes as $node) {
            $property = $node->getAttribute('property');
            $content = trim($node->getAttribute('content'));
            if ($content === '') {
                continue;
            }

            if ($property === 'og:image' || $property === 'og:image:url') {
                $url = $this->resolveUrl($content, $context->baseUrl);
                $pendingImage = $url ? ['url' => $url, 'width' => null, 'height' => null] : null;
            } elseif ($property === 'og:image:width' && $pendingImage) {
                $pendingImage['width'] = (int) $content;
            } elseif ($property === 'og:image:height' && $pendingImage) {
                $pendingImage['height'] = (int) $content;
            } elseif ($property === 'og:video' || $property === 'og:video:url') {
                $url = $this->resolveUrl($content, $context->baseUrl);
                $pendingVideo = $url ? ['url' => $url] : null;
            }
        }

        if ($pendingImage) {
            $out[] = new ExtractedMedia(
                url: $pendingImage['url'],
                type: MediaType::Image,
                extractedBy: 'og_meta',
                width: $pendingImage['width'],
                height: $pendingImage['height'],
                position: $startPosition,
                isFeaturedHint: true,
            );
        }

        if ($pendingVideo) {
            $out[] = new ExtractedMedia(
                url: $pendingVideo['url'],
                type: MediaType::Video,
                extractedBy: 'og_meta',
                position: $startPosition + 1,
                isFeaturedHint: true,
            );
        }

        return $out;
    }

    /** @return list<ExtractedMedia> */
    private function extractTwitterCard(\DOMXPath $xpath, ExtractionContext $context, int $startPosition): array
    {
        $out = [];
        $nodes = $xpath->query('//meta[@name="twitter:image" or @name="twitter:player"]');

        foreach ($nodes as $i => $node) {
            $name = $node->getAttribute('name');
            $content = trim($node->getAttribute('content'));
            $url = $this->resolveUrl($content, $context->baseUrl);
            if (! $url) {
                continue;
            }

            $out[] = new ExtractedMedia(
                url: $url,
                type: $name === 'twitter:player' ? MediaType::Embed : MediaType::Image,
                extractedBy: 'twitter_card',
                position: $startPosition + $i,
                isFeaturedHint: false,
            );
        }

        return $out;
    }

    /** @return list<ExtractedMedia> */
    private function extractJsonLd(\DOMDocument $dom, ExtractionContext $context, int $startPosition): array
    {
        $out = [];
        $scripts = $dom->getElementsByTagName('script');

        foreach ($scripts as $script) {
            if (strtolower($script->getAttribute('type')) !== 'application/ld+json') {
                continue;
            }

            $data = json_decode($script->textContent, true);
            if (! is_array($data)) {
                continue;
            }

            foreach ($this->flattenJsonLdImages($data) as $url) {
                $resolved = $this->resolveUrl($url, $context->baseUrl);
                if ($resolved) {
                    $out[] = new ExtractedMedia(
                        url: $resolved,
                        type: MediaType::Image,
                        extractedBy: 'json_ld',
                        position: $startPosition + count($out),
                    );
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function flattenJsonLdImages(array $data): array
    {
        $urls = [];
        $image = $data['image'] ?? null;

        if (is_string($image)) {
            $urls[] = $image;
        } elseif (is_array($image)) {
            if (Str::isJson(json_encode($image)) && isset($image['url'])) {
                $urls[] = $image['url'];
            } else {
                foreach ($image as $entry) {
                    if (is_string($entry)) {
                        $urls[] = $entry;
                    } elseif (is_array($entry) && isset($entry['url'])) {
                        $urls[] = $entry['url'];
                    }
                }
            }
        }

        foreach ($data['@graph'] ?? [] as $node) {
            if (is_array($node)) {
                $urls = array_merge($urls, $this->flattenJsonLdImages($node));
            }
        }

        return $urls;
    }
}
