<?php

namespace App\Services\Media\Extraction;

use App\DTOs\ExtractedMedia;
use App\DTOs\ExtractionContext;
use App\Enums\MediaType;
use Illuminate\Support\Collection;

/**
 * Extracts media referenced directly in the article body markup: <img>, <picture>
 * (largest candidate from srcset), <video>/<source>, and known-video <iframe> embeds.
 */
class HtmlContentExtractor implements MediaExtractorInterface
{
    use ResolvesUrls;

    public function supports(ExtractionContext $context): bool
    {
        return filled($context->html);
    }

    public function extract(ExtractionContext $context): Collection
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$context->html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            return collect();
        }

        $results = collect();
        $position = 0;

        foreach ($dom->getElementsByTagName('picture') as $picture) {
            if ($item = $this->fromPicture($picture, $context, $position)) {
                $results->push($item);
                $position++;
            }
        }

        $picturesImages = $this->collectDescendantImgs($dom, 'picture');

        foreach ($dom->getElementsByTagName('img') as $img) {
            if (in_array($img, $picturesImages, true)) {
                continue; // already handled via <picture>
            }
            if ($item = $this->fromImg($img, $context, $position)) {
                $results->push($item);
                $position++;
            }
        }

        foreach ($dom->getElementsByTagName('video') as $video) {
            foreach ($this->fromVideo($video, $context, $position) as $item) {
                $results->push($item);
                $position++;
            }
        }

        foreach ($dom->getElementsByTagName('iframe') as $iframe) {
            if ($item = $this->fromIframe($iframe, $context, $position)) {
                $results->push($item);
                $position++;
            }
        }

        return $results->unique(fn (ExtractedMedia $m) => $m->fingerprint())->values();
    }

    /** @return \DOMNode[] */
    private function collectDescendantImgs(\DOMDocument $dom, string $ancestorTag): array
    {
        $imgs = [];
        foreach ($dom->getElementsByTagName($ancestorTag) as $ancestor) {
            foreach ($ancestor->getElementsByTagName('img') as $img) {
                $imgs[] = $img;
            }
        }

        return $imgs;
    }

    private function fromImg(\DOMElement $img, ExtractionContext $context, int $position): ?ExtractedMedia
    {
        $src = $img->getAttribute('srcset') ? $this->pickLargestFromSrcset($img->getAttribute('srcset')) : null;
        $src = $src ?: ($img->getAttribute('src') ?: $img->getAttribute('data-src'));

        $url = $this->resolveUrl($src, $context->baseUrl);
        if (! $url) {
            return null;
        }

        $width = $img->hasAttribute('width') ? (int) $img->getAttribute('width') : null;
        $height = $img->hasAttribute('height') ? (int) $img->getAttribute('height') : null;

        return new ExtractedMedia(
            url: $url,
            type: str_ends_with(strtolower($url), '.gif') ? MediaType::Gif : MediaType::Image,
            extractedBy: 'html_img',
            altText: $img->getAttribute('alt') ?: null,
            width: $width ?: null,
            height: $height ?: null,
            position: $position,
        );
    }

    private function fromPicture(\DOMElement $picture, ExtractionContext $context, int $position): ?ExtractedMedia
    {
        foreach ($picture->getElementsByTagName('source') as $source) {
            $srcset = $source->getAttribute('srcset');
            if ($srcset && ($url = $this->pickLargestFromSrcset($srcset))) {
                $resolved = $this->resolveUrl($url, $context->baseUrl);
                if ($resolved) {
                    return new ExtractedMedia($resolved, MediaType::Image, 'html_picture', position: $position);
                }
            }
        }

        foreach ($picture->getElementsByTagName('img') as $img) {
            return $this->fromImg($img, $context, $position);
        }

        return null;
    }

    /** @return list<ExtractedMedia> */
    private function fromVideo(\DOMElement $video, ExtractionContext $context, int $position): array
    {
        $out = [];
        $poster = $this->resolveUrl($video->getAttribute('poster'), $context->baseUrl);

        $candidates = [];
        if ($video->getAttribute('src')) {
            $candidates[] = $video->getAttribute('src');
        }
        foreach ($video->getElementsByTagName('source') as $source) {
            if ($source->getAttribute('src')) {
                $candidates[] = $source->getAttribute('src');
            }
        }

        foreach (array_unique($candidates) as $i => $src) {
            $url = $this->resolveUrl($src, $context->baseUrl);
            if (! $url) {
                continue;
            }

            $out[] = new ExtractedMedia(
                url: $url,
                type: MediaType::Video,
                extractedBy: 'html_video',
                thumbnailUrl: $poster,
                position: $position + $i,
            );
        }

        return $out;
    }

    private function fromIframe(\DOMElement $iframe, ExtractionContext $context, int $position): ?ExtractedMedia
    {
        $src = $iframe->getAttribute('src');
        $url = $this->resolveUrl($src, $context->baseUrl);
        if (! $url) {
            return null;
        }

        // Recognizable video-embed hosts are worth keeping as an embed candidate;
        // arbitrary third-party iframes (ads, widgets) are not.
        $videoHosts = ['youtube.com', 'youtube-nocookie.com', 'player.vimeo.com', 'dailymotion.com'];
        $host = parse_url($url, PHP_URL_HOST) ?: '';

        foreach ($videoHosts as $needle) {
            if (str_contains($host, $needle)) {
                return new ExtractedMedia($url, MediaType::Embed, 'html_iframe', position: $position);
            }
        }

        return null;
    }

    private function pickLargestFromSrcset(string $srcset): ?string
    {
        $best = null;
        $bestWidth = -1;

        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));
            if (empty($parts[0])) {
                continue;
            }
            $descriptor = $parts[1] ?? '1x';
            $width = str_ends_with($descriptor, 'w')
                ? (int) rtrim($descriptor, 'w')
                : (int) (100 * (float) rtrim($descriptor, 'x'));

            if ($width > $bestWidth) {
                $bestWidth = $width;
                $best = $parts[0];
            }
        }

        return $best;
    }
}
