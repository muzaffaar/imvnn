<?php

namespace App\Services\Media\Scoring;

use App\Enums\ImageRole;

/**
 * Decides what an image *is* — article content, an author avatar, site chrome,
 * a tracking pixel, an ad — from its URL, its alt text, and its dimensions
 * when those are known. No network calls, no model.
 *
 * This exists because quality scoring cannot answer the question. An avatar is
 * a genuinely high-quality image: sharp, well-compressed, ideally sized for
 * Telegram. Observed on a Hugging Face article, ten of sixteen candidates were
 * contributor avatars from `cdn-avatars.huggingface.co`, scoring 0.57-0.69
 * against a 0.35 floor while the article's own figures scored 0.61 — so they
 * ranked *above* the real content and filled album slots with strangers' faces.
 *
 * Deliberately cheap and run twice, because the useful signals arrive at
 * different times:
 *
 *   1. At extraction, from URL and alt text alone, to avoid creating a row and
 *      scoring something we will never publish.
 *   2. At selection, after dimensions have been probed, which is the only
 *      point the small-square test can fire — and that test is what catches
 *      the avatar CDNs nobody has added a pattern for yet.
 *
 * Patterns are matched against the URL *path*, never the host alone, except
 * for the host markers listed separately: a CDN hostname like
 * `cdn-avatars.example.com` is decisive, while `avatars` appearing in a query
 * string usually is not.
 */
class ImageRoleClassifier
{
    public function classify(
        string $url,
        ?string $altText = null,
        ?int $width = null,
        ?int $height = null,
    ): ImageRole {
        if ($this->isPixel($width, $height)) {
            return ImageRole::Pixel;
        }

        $path = $this->searchablePath($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $alt = mb_strtolower(trim((string) $altText));

        if ($this->matchesAny($host, $this->config('avatar_hosts'))
            || $this->matchesAny($path, $this->config('avatar_url_markers'))
            || $this->matchesAny($alt, $this->config('avatar_alt_markers'))) {
            return ImageRole::Avatar;
        }

        if ($this->matchesAny($path, $this->config('promo_url_markers'))) {
            return ImageRole::Promo;
        }

        if ($this->matchesAny($path, $this->config('chrome_url_markers'))
            || $this->matchesAny($alt, $this->config('chrome_alt_markers'))) {
            return ImageRole::Chrome;
        }

        // Dimension-based catch-all, and the only rule that needs no pattern
        // list kept up to date. A small square is an avatar or an icon in
        // practically every case: article photography and charts are
        // landscape or portrait, and a genuine square illustration worth
        // publishing is larger than a profile thumbnail.
        [$width, $height] = $this->withFilenameDimensions($url, $width, $height);

        if ($this->isSmallSquare($width, $height)) {
            return ImageRole::Avatar;
        }

        return ImageRole::Content;
    }

    /**
     * Falls back to dimensions encoded in the filename when none were supplied.
     *
     * WordPress and most CMS thumbnailers name the rendition after its size —
     * `ali-kani-scaled-96x96.jpg` — which is the only size signal available at
     * extraction time, where an `<img>` often carries no width or height
     * attribute. Without it that 96x96 author headshot reads as ordinary
     * content until a probe happens much later, having already been stored,
     * scored and ranked against the article's real pictures.
     *
     * The LAST size group in the name wins: a chained rendition such as
     * `nv-blog-1280x680-1-960x540.jpg` was finally resized to 960x540, and the
     * earlier number is the original it came from.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function withFilenameDimensions(string $url, ?int $width, ?int $height): array
    {
        if ($width && $height) {
            return [$width, $height];
        }

        $name = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_FILENAME);

        if (! preg_match_all('/(?<w>\d{2,5})x(?<h>\d{2,5})/', $name, $matches, PREG_SET_ORDER)) {
            return [$width, $height];
        }

        $last = end($matches);

        return [(int) $last['w'], (int) $last['h']];
    }

    /**
     * Only the bits of a URL a human would read as naming the image: the path
     * plus any filename-bearing query values. Host is checked separately.
     */
    private function searchablePath(string $url): string
    {
        $parts = parse_url($url) ?: [];

        return mb_strtolower(($parts['path'] ?? $url).'?'.($parts['query'] ?? ''));
    }

    private function isPixel(?int $width, ?int $height): bool
    {
        $maxEdge = (int) $this->config('pixel_max_edge', 2);

        return ($width !== null && $width > 0 && $width <= $maxEdge)
            || ($height !== null && $height > 0 && $height <= $maxEdge);
    }

    private function isSmallSquare(?int $width, ?int $height): bool
    {
        if (! $width || ! $height) {
            return false; // unknown — judged again after probing
        }

        $maxEdge = (int) $this->config('square_icon_max_edge', 400);
        $tolerance = (float) $this->config('square_tolerance', 0.12);

        if (max($width, $height) > $maxEdge) {
            return false;
        }

        $ratio = $width / $height;

        return abs($ratio - 1.0) <= $tolerance;
    }

    /** @param list<string> $needles */
    private function matchesAny(string $haystack, array $needles): bool
    {
        if ($haystack === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function config(string $key, mixed $default = []): mixed
    {
        return config("media.image_roles.{$key}", $default);
    }
}
