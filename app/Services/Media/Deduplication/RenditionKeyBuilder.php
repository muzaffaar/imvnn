<?php

namespace App\Services\Media\Deduplication;

use App\Models\MediaAsset;

/**
 * "Same picture" identities for assets we never downloaded, and the rules for
 * comparing them.
 *
 * An asset carries several identities at once — always a key derived from its
 * URL, and a perceptual or content hash once something has looked at the
 * bytes. `keysFor` returns all of them and `isSamePictureByKeys` matches on
 * any pair, so learning a hash can only merge more copies, never fewer.
 *
 * CDNs routinely serve one image under several URLs — confirmed on
 * research.google, where every article figure appears as both
 * `original_images/GlucoFM1_Overview.png` and
 * `images/GlucoFM1_Overview.width-1250.png`, and on blog.google as
 * `WeatherNext3_Title.width-1300.png` / `WeatherNext3_Title.width-200.format-webp.webp`.
 * Level 1 (URL) dedup misses these because the URLs genuinely differ, and
 * Level 2 (content hash) never runs for reference-only assets because we
 * deliberately never copy their bytes. Without this, the same picture is
 * attached to a Telegram post two or three times.
 *
 * Filenames still have a floor: nothing here can relate two names that share
 * no stem. ImageFingerprintProbe crosses that floor at selection time by
 * giving reference-only assets a perceptual hash, which is compared here by
 * Hamming distance rather than by equality.
 *
 * Deliberately keyed on host + normalized *filename*, ignoring the
 * directory — that's the whole point, since renditions of one image live in
 * different directories. To keep that from merging two genuinely different
 * images that happen to share a common filename (`hero.jpg` under two
 * article paths), a name shorter than MIN_DISTINCTIVE_LENGTH falls back to
 * the full path instead.
 */
class RenditionKeyBuilder
{
    private const MIN_DISTINCTIVE_LENGTH = 8;

    public function __construct(private readonly PerceptualHasher $perceptualHasher) {}

    /** Rendition markers CDNs append; applied repeatedly since they chain. */
    private const RENDITION_PATTERNS = [
        '/\.(width|height|max|min|fill|crop|scale|resize)-\d+(x\d+)?$/i',
        // A bare keyword left behind once its dimensions have been stripped by
        // a later pattern in this same list. `Hero_Image_4.max-600x600.format-webp`
        // loses `.format-webp`, then `-600x600`, which leaves `.max` stranded
        // where the first pattern can no longer see it — so that rendition kept
        // its own key and the same hero image reached an album twice.
        '/\.(width|height|max|min|fill|crop|scale|resize)$/i',
        '/\.format-[a-z0-9]+$/i',
        '/\.[0-9a-f]{6,40}$/i',                 // cache-busting content hash: Foo.2e16d0ba
        '/[-_]\d{2,4}x\d{2,4}$/',               // Foo-600x600
        '/@\d+x$/i',                            // Foo@2x
        '/[-_](thumb|thumbnail|small|medium|large|scaled|preview|resized)$/i',
    ];

    /**
     * Minimum shared prefix before a truncation match is even considered.
     * Short stems are not distinctive enough to collapse on.
     */
    private const MIN_TRUNCATION_PREFIX = 12;

    /**
     * Whether two keys name the same picture, allowing for a filename the CMS
     * truncated differently per rendition.
     *
     * blog.google does exactly that, cutting the stem to a length that varies
     * with the rendition suffix:
     *
     *   LOVE_RENDERED_HERO_BLOG_SANS_LOG.width-2200.format-webp.webp
     *   LOVE_RENDERED_HERO_BLOG_SANS_LO.max-600x600.format-webp.webp
     *
     * One image, two stems differing by a single character, so exact key
     * equality keeps both and the album shows the same picture twice.
     *
     * A plain prefix test would be wrong, and the counter-example is on the same
     * site: `love_rendered_inline` is a prefix of `love_rendered_inline_2`, and
     * those are two different pictures. The distinction is *where* the shorter
     * stem stops. A truncation cuts mid-word — `…sans_lo` continues into `g`,
     * `…usage_f` into `o` — whereas a numbered sibling stops at a separator.
     * So a prefix only counts as a truncation when the next character of the
     * longer stem is not a separator.
     */
    public function isSamePicture(string $first, string $second): bool
    {
        if ($first === $second) {
            return true;
        }

        // Pixels outrank filenames. Two perceptual hashes within the dedup
        // threshold are the same photograph however differently the CMS chose
        // to name each copy — which is the only thing that catches a picture
        // republished under an unrelated filename. Both hashes must carry
        // real structure first; see PerceptualHasher::isDistinctive.
        if (str_starts_with($first, 'phash:') && str_starts_with($second, 'phash:')) {
            return $this->isSamePerceptualHash(substr($first, 6), substr($second, 6));
        }

        // Only URL-derived keys can be truncated. A content or perceptual hash
        // is exact, and a provider id is authoritative.
        if (! str_starts_with($first, 'url:') || ! str_starts_with($second, 'url:')) {
            return false;
        }

        [$shorter, $longer] = mb_strlen($first) <= mb_strlen($second) ? [$first, $second] : [$second, $first];

        if (! str_starts_with($longer, $shorter)) {
            return false;
        }

        if (mb_strlen($shorter) - mb_strlen('url:') < self::MIN_TRUNCATION_PREFIX) {
            return false;
        }

        $nextCharacter = mb_substr($longer, mb_strlen($shorter), 1);
        $lastCharacter = mb_substr($shorter, -1);

        return ! $this->isSeparator($nextCharacter) && ! $this->isSeparator($lastCharacter);
    }

    /**
     * Whether any identity of one picture matches any identity of another.
     *
     * Assets carry several identities at once — a URL stem, and, once the
     * bytes have been looked at, a perceptual hash — and the two do not
     * arrive together. Comparing only the strongest available identity is
     * what let a duplicate through: fingerprinting one rendition of a picture
     * moved it from a `url:` key to a `phash:` key, so it stopped matching
     * the un-fingerprinted rendition it had always matched before. Keeping
     * every identity and matching on any of them means new evidence can only
     * ever merge more copies, never fewer.
     *
     * @param  list<string>  $first
     * @param  list<string>  $second
     */
    public function isSamePictureByKeys(array $first, array $second): bool
    {
        foreach ($first as $a) {
            foreach ($second as $b) {
                if ($this->isSamePicture($a, $b)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every identity an asset currently has, strongest first. The URL key is
     * always present, so an asset never loses the identity it was grouped by
     * when a stronger one is learned.
     *
     * @return list<string>
     */
    public function keysFor(MediaAsset $asset): array
    {
        $keys = [];

        if ($asset->perceptual_hash) {
            $keys[] = 'phash:'.$asset->perceptual_hash;
        }

        if ($asset->content_hash) {
            $keys[] = 'hash:'.$asset->content_hash;
        }

        if ($asset->external_provider && $asset->external_id) {
            $keys[] = "{$asset->external_provider}:{$asset->external_id}";
        }

        $keys[] = 'url:'.$this->visualKeyFromUrl($asset->original_url);

        return $keys;
    }

    private function isSamePerceptualHash(string $first, string $second): bool
    {
        if (! $this->perceptualHasher->isDistinctive($first)
            || ! $this->perceptualHasher->isDistinctive($second)) {
            return false;
        }

        $threshold = (int) config('media.deduplication.perceptual_hash_hamming_threshold');

        return $this->perceptualHasher->hammingDistance($first, $second) <= $threshold;
    }

    private function isSeparator(string $character): bool
    {
        return $character === '' || in_array($character, ['-', '_', '.', '/', '|'], true);
    }

    public function keyFor(MediaAsset $asset): string
    {
        // When the bytes have been looked at, what they depict beats any URL
        // guessing. The perceptual hash leads rather than the content hash
        // because it also matches across renditions, and two byte-identical
        // files necessarily share it anyway.
        return $this->keysFor($asset)[0];
    }

    private function visualKeyFromUrl(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $path = parse_url($url, PHP_URL_PATH) ?? $url;
        $name = pathinfo($path, PATHINFO_FILENAME);

        $normalized = $this->stripRenditionMarkers($this->stripSizeSuffix($name));

        if (mb_strlen($normalized) < self::MIN_DISTINCTIVE_LENGTH) {
            return $host.'|'.strtolower(trim($path, '/'));
        }

        return $host.'|'.mb_strtolower($normalized);
    }

    /**
     * Google's image CDNs encode the rendition as an `=`-delimited option
     * string glued straight onto the image id, with no dot and no extension:
     * `…vOjFcTcd…=w1200-h630-n-nu-rw`. Every pattern in RENDITION_PATTERNS
     * anchors on a `.` or a trailing `-NNNxNNN`, so none of them touch it, and
     * one image served at five sizes produced five different keys — which is
     * exactly how the same picture reached a Telegram album twice (observed on
     * a DeepMind article: `=w2000-h1260` and `=w1920-h1080` of one figure
     * occupied two of four album slots).
     *
     * Splitting on the first `=` is safe because the id itself never contains
     * one: it is base64url, whose alphabet excludes `=` except as terminal
     * padding, which these ids do not carry.
     */
    private function stripSizeSuffix(string $name): string
    {
        $position = strpos($name, '=');

        if ($position === false || $position === 0) {
            return $name;
        }

        return substr($name, 0, $position);
    }

    private function stripRenditionMarkers(string $name): string
    {
        // Markers chain (Foo.width-200.format-webp), so keep stripping until
        // a full pass changes nothing.
        do {
            $before = $name;

            foreach (self::RENDITION_PATTERNS as $pattern) {
                $name = preg_replace($pattern, '', $name) ?? $name;
            }
        } while ($name !== $before && $name !== '');

        return $name;
    }
}
