<?php

namespace App\Services\Media\Extraction;

use App\DTOs\ExtractedMedia;
use App\DTOs\ExtractionContext;
use App\Enums\MediaType;
use App\Services\News\ArticleBodyLocator;
use Illuminate\Support\Collection;

/**
 * Extracts media referenced directly in the article body markup: <img>, <picture>
 * (largest candidate from srcset), <video>/<source>, and known-video <iframe> embeds.
 */
class HtmlContentExtractor implements MediaExtractorInterface
{
    use ResolvesUrls;

    public function __construct(private readonly ArticleBodyLocator $bodyLocator) {}

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

        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query($this->nonArticleContainerQuery())) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $this->dropEverythingBelowTheArticle($xpath);

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
            if ($this->linksToAnotherArticle($img, $context->baseUrl)) {
                continue;
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

    /**
     * Containers whose images are never the article's own pictures, removed
     * before anything is collected.
     *
     * `<nav>`, `<aside>` and `<footer>` were already here. The class/id matching
     * adds the containers avatars actually come from: bylines, contributor
     * lists and comment threads. Matching is on a hyphen-padded class string so
     * `author` cannot match `authoritative`, and `<header>` is deliberately NOT
     * removed wholesale: on several of the configured sources the article's
     * hero image lives inside it.
     *
     * The list is deliberately narrow, and limited to words that can only
     * describe a person or a discussion. Broader layout words cannot be used
     * here: `related` and `sidebar` were tried and both matched a wrapper
     * *around* the article on the NVIDIA blog — classes like `sidebar-right`
     * describe the page's layout, not the element's role — which removed the
     * article's own figures along with everything else, cutting one post from
     * seven candidates to four.
     *
     * So this is a cheap structural pass, not the real defence.
     * ImageRoleClassifier judges every surviving candidate, which is what
     * handles logos, ads and promos, and the bylines that carry no usable
     * class at all.
     */
    private function nonArticleContainerQuery(): string
    {
        $markers = [
            'author', 'byline', 'contributor', 'commenter', 'comments',
            'avatar', 'gravatar', 'profile-pic', 'profile-photo', 'userpic',
        ];

        $clauses = ['//nav', '//aside', '//footer', '//*[@role="navigation" or @role="complementary"]'];

        foreach ($markers as $marker) {
            // Pad both the attribute and the needle with hyphens so a marker
            // only matches a whole hyphen- or space-delimited token.
            $padded = "concat('-', translate(normalize-space(@class), ' ', '-'), '-')";
            $clauses[] = "//*[contains({$padded}, '-{$marker}-')]";
            $clauses[] = "//*[contains(concat('-', @id, '-'), '-{$marker}-')]";
        }

        return implode(' | ', $clauses);
    }

    /**
     * True when the image is wrapped in a link pointing at some *other* page —
     * i.e. it is a teaser thumbnail for a different article, not a picture of
     * this one.
     *
     * This is the general form of the "related posts" problem, and a far more
     * reliable signal than class names. On blog.google every recommended-article
     * thumbnail sits inside `<a class="uni-article-card" href="/other-story">`,
     * and six of them were extracted for one password-manager article, two
     * reaching the published album. Class matching cannot be used here: the
     * obvious marker, `related`, also matched a wrapper around the article body
     * on another source and removed its real figures.
     *
     * Three kinds of enclosing link are deliberately kept:
     *
     *  - a link to the image itself (a lightbox), recognised by an image
     *    extension on the href;
     *  - a link back to this same article, which is how some templates wrap
     *    their own hero image;
     *  - anything that is not an ordinary http(s) page link — `#`, `mailto:`,
     *    a javascript handler — since none of those indicate another article.
     */
    private function linksToAnotherArticle(\DOMElement $img, string $baseUrl): bool
    {
        $anchor = $this->enclosingAnchor($img);

        if (! $anchor) {
            return false;
        }

        $href = $this->resolveUrl($anchor->getAttribute('href'), $baseUrl);

        if (! $href) {
            return false;
        }

        $hrefPath = (string) parse_url($href, PHP_URL_PATH);

        if (in_array(strtolower(pathinfo($hrefPath, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
            return false; // lightbox link to the picture itself
        }

        $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);

        return rtrim($hrefPath, '/') !== rtrim($basePath, '/');
    }

    private function enclosingAnchor(\DOMElement $img): ?\DOMElement
    {
        for ($node = $img->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
            if ($node->nodeName === 'a' && $node->hasAttribute('href')) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Removes the part of the page that comes *after* the article body — the
     * "related posts", "read next" and "more from us" strip sitting directly
     * below the story.
     *
     * Those thumbnails are pictures of other articles, and they were reaching
     * published albums: one blog.google page contributed six of them. They are
     * also the hardest kind to recognise one at a time, since they are real
     * photographs at real sizes with sensible alt text. Their position is the
     * giveaway.
     *
     * Only what follows the body is removed, never what precedes it, and that
     * asymmetry is deliberate and measured. A hero image very often sits
     * *before* the body container — in a page header, a `<picture>` block, or a
     * sibling `<figure>` — so dropping the region above the article would throw
     * away the single most important image on the page. Nothing above is
     * dropped here; the role classifier and the teaser-link rule handle the
     * navigation icons and promo panels that live up there.
     *
     * Scoping *into* the body instead of trimming after it was tried first and
     * is wrong for the same reason: measured across the configured sources, the
     * hero and sometimes the article's own charts fall outside the densest
     * container on blog.google, the NVIDIA blog, Hugging Face and the AWS blog.
     *
     * A page whose body cannot be located is left entirely alone, since with no
     * anchor there is no "after".
     */
    private function dropEverythingBelowTheArticle(\DOMXPath $xpath): void
    {
        $body = $this->bodyLocator->locate($xpath);

        if (! $body) {
            return;
        }

        $order = new \SplObjectStorage;
        $index = 0;

        foreach ($xpath->query('//*') ?: [] as $element) {
            $order[$element] = $index++;
        }

        if (! $order->contains($body)) {
            return;
        }

        $lastIndexInBody = $order[$body];
        foreach ($body->getElementsByTagName('*') as $descendant) {
            if ($order->contains($descendant)) {
                $lastIndexInBody = max($lastIndexInBody, $order[$descendant]);
            }
        }

        // Collect first, remove after: detaching a node mid-iteration would
        // shift the live NodeList out from under the loop.
        $doomed = [];
        foreach ($xpath->query('//img | //picture | //video | //iframe') ?: [] as $media) {
            if ($order->contains($media) && $order[$media] > $lastIndexInBody) {
                $doomed[] = $media;
            }
        }

        foreach ($doomed as $media) {
            $media->parentNode?->removeChild($media);
        }
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

    /**
     * Lazy-loading attributes, in preference order. `src` comes last on
     * purpose: a lazy-loaded image's `src` is usually a placeholder — a blur,
     * a spinner, or a data: URI — while the real picture sits in one of the
     * data attributes. Reading `src` first gets the placeholder and discards
     * the photograph.
     */
    private const SRC_ATTRIBUTES = [
        'data-src', 'data-original', 'data-lazy-src', 'data-lazy',
        'data-image', 'data-hi-res-src', 'data-full-src', 'src',
    ];

    private const SRCSET_ATTRIBUTES = ['data-srcset', 'srcset', 'data-lazy-srcset'];

    /** Hrefs ending in one of these are a link to the picture, not to a page. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    private function fromImg(\DOMElement $img, ExtractionContext $context, int $position): ?ExtractedMedia
    {
        $src = null;

        foreach (self::SRCSET_ATTRIBUTES as $attribute) {
            if ($value = trim($img->getAttribute($attribute))) {
                $src = $this->pickLargestFromSrcset($value);
                if ($src) {
                    break;
                }
            }
        }

        if (! $src) {
            foreach (self::SRC_ATTRIBUTES as $attribute) {
                if ($value = trim($img->getAttribute($attribute))) {
                    $src = $value;
                    break;
                }
            }
        }

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
            if (strtolower($host) === $needle || str_ends_with(strtolower($host), '.'.$needle)) {
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
