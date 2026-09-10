<?php

namespace App\Services\News;

use App\DTOs\RawArticleCandidate;
use App\Enums\SourceFetchType;
use App\Models\Source;
use App\Services\Http\BoundedHttpFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use SimpleXMLElement;

/**
 * Polls an RSS 2.0 or Atom feed directly. Produces the `rssItem` shape
 * RssMediaExtractor already knows how to read (media:content, media:thumbnail,
 * enclosure), so an RSS-sourced article gets full media extraction without
 * the extraction pipeline needing to know it came from a feed.
 */
class RssSourceFetcher implements NewsSourceFetcherInterface
{
    public function __construct(private readonly BoundedHttpFetcher $fetcher) {}

    public function supports(Source $source): bool
    {
        return $source->fetch_type === SourceFetchType::Rss;
    }

    public function fetch(Source $source): Collection
    {
        $limits = config('news_sources.limits');

        $xml = $this->fetcher->downloadToMemory(
            $source->source_url,
            $limits['max_feed_bytes'],
            $limits['download_timeout_seconds'],
            $limits['download_connect_timeout_seconds'],
        );

        $feed = $this->parseXml($xml);
        if (! $feed) {
            return collect();
        }

        $namespaces = $feed->getNamespaces(true);
        $isAtom = isset($feed->entry) || $feed->getName() === 'feed';

        $items = $isAtom ? $feed->entry : ($feed->channel->item ?? null);

        $results = collect();

        // Deliberately a plain foreach, not collect($items)->map(...): every
        // sibling <item>/<entry> shares the same SimpleXML iterator key, and
        // Collection::make() converts via iterator_to_array($items) — which
        // defaults to preserving keys, silently collapsing all-but-one of
        // several same-keyed siblings into a single array slot. Confirmed
        // against a real 10-item feed that this returns 1.
        if ($items !== null) {
            foreach ($items as $item) {
                $results->push($isAtom ? $this->fromAtomEntry($item, $namespaces) : $this->fromRssItem($item, $namespaces));
            }
        }

        return $results->filter(fn (?RawArticleCandidate $c) => $c !== null)
            ->values()
            ->take($limits['max_items_per_feed_fetch']);
    }

    private function parseXml(string $xml): ?SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return $parsed ?: null;
    }

    private function fromRssItem(SimpleXMLElement $item, array $namespaces): ?RawArticleCandidate
    {
        $url = trim((string) $item->link) ?: trim((string) $item->guid);
        if ($url === '' || ! str_starts_with($url, 'http')) {
            return null;
        }

        $media = isset($namespaces['media']) ? $item->children($namespaces['media']) : null;
        $content = isset($namespaces['content']) ? $item->children($namespaces['content']) : null;

        return new RawArticleCandidate(
            url: $url,
            title: $this->clean((string) $item->title),
            summary: $this->clean((string) ($content?->encoded ?: $item->description)),
            publishedAt: $this->parseDate((string) $item->pubDate),
            rssItem: [
                'media_contents' => $this->extractMediaContents($media),
                'media_thumbnails' => $this->extractMediaThumbnails($media),
                'enclosures' => $this->extractEnclosure($item),
            ],
        );
    }

    private function fromAtomEntry(SimpleXMLElement $entry, array $namespaces): ?RawArticleCandidate
    {
        $url = null;
        foreach ($entry->link as $link) {
            $attrs = $link->attributes();
            if (! isset($attrs['rel']) || (string) $attrs['rel'] === 'alternate') {
                $url = (string) $attrs['href'];
                break;
            }
        }
        $url ??= trim((string) $entry->id);

        if (! $url || ! str_starts_with($url, 'http')) {
            return null;
        }

        $media = isset($namespaces['media']) ? $entry->children($namespaces['media']) : null;

        return new RawArticleCandidate(
            url: $url,
            title: $this->clean((string) $entry->title),
            summary: $this->clean((string) ($entry->summary ?: $entry->content)),
            publishedAt: $this->parseDate((string) ($entry->published ?: $entry->updated)),
            rssItem: [
                'media_contents' => $this->extractMediaContents($media),
                'media_thumbnails' => $this->extractMediaThumbnails($media),
                'enclosures' => [],
            ],
        );
    }

    private function extractMediaContents(?SimpleXMLElement $media): array
    {
        if (! $media || ! isset($media->content)) {
            return [];
        }

        $out = [];
        foreach ($media->content as $content) {
            $attrs = $content->attributes();
            if (empty($attrs['url'])) {
                continue;
            }

            $out[] = array_filter([
                'url' => (string) $attrs['url'],
                'medium' => isset($attrs['medium']) ? (string) $attrs['medium'] : null,
                'width' => isset($attrs['width']) ? (int) $attrs['width'] : null,
                'height' => isset($attrs['height']) ? (int) $attrs['height'] : null,
                'duration' => isset($attrs['duration']) ? (int) $attrs['duration'] : null,
                'is_default' => isset($attrs['isDefault']) && (string) $attrs['isDefault'] === 'true',
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    private function extractMediaThumbnails(?SimpleXMLElement $media): array
    {
        if (! $media || ! isset($media->thumbnail)) {
            return [];
        }

        $out = [];
        foreach ($media->thumbnail as $thumb) {
            $attrs = $thumb->attributes();
            if (empty($attrs['url'])) {
                continue;
            }

            $out[] = array_filter([
                'url' => (string) $attrs['url'],
                'width' => isset($attrs['width']) ? (int) $attrs['width'] : null,
                'height' => isset($attrs['height']) ? (int) $attrs['height'] : null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    private function extractEnclosure(SimpleXMLElement $item): array
    {
        if (! isset($item->enclosure)) {
            return [];
        }

        $attrs = $item->enclosure->attributes();
        if (empty($attrs['url'])) {
            return [];
        }

        return [array_filter([
            'url' => (string) $attrs['url'],
            'type' => isset($attrs['type']) ? (string) $attrs['type'] : null,
        ], fn ($v) => $v !== null)];
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function clean(string $value): ?string
    {
        $value = trim(html_entity_decode(strip_tags($value)));

        return $value !== '' ? $value : null;
    }
}
