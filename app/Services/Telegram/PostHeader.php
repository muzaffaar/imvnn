<?php

namespace App\Services\Telegram;

use App\Models\NewsItem;

/**
 * The bits of a post that don't depend on which composer produced the body:
 * a bold source line, a mandatory article link, and topical hashtags.
 * Shared so the AI and plain composers can't drift apart in look.
 *
 * Telegram's HTML parse_mode supports only a small tag set (<b>, <i>, <a>,
 * <code>, <blockquote>, ...) — no headings, lists or rules — so structure
 * here comes from emoji, bold, and line breaks rather than markup.
 */
class PostHeader
{
    private const MAX_HASHTAGS = 4;

    public static function render(NewsItem $newsItem): ?string
    {
        $source = $newsItem->source?->name;

        $lines = $source ? ['📰 <b>'.TelegramHtml::escape($source).'</b>'] : [];
        $lines[] = '🔗 '.self::renderArticleLink($newsItem);

        return implode("\n", $lines);
    }

    /**
     * Every published post must retain a safe, direct link to the original
     * article. Canonical URLs win when the parser found one.
     */
    private static function renderArticleLink(NewsItem $newsItem): string
    {
        $url = $newsItem->canonical_url ?: $newsItem->url;
        $scheme = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : null;

        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException("News item {$newsItem->id} has no safe article URL for Telegram.");
        }

        return '<a href="'.TelegramHtml::escapeAttribute($url).'">manba</a>';
    }

    /** @param mixed $hashtags whatever the model returned — validated here, not trusted */
    public static function renderHashtags(mixed $hashtags): ?string
    {
        if (! is_array($hashtags)) {
            return null;
        }

        $tags = collect($hashtags)
            ->filter(fn ($tag) => is_string($tag))
            // Telegram only treats [A-Za-z0-9_] as part of a hashtag, so strip
            // everything else rather than emitting a tag that renders half-plain.
            ->map(fn (string $tag) => preg_replace('/[^\p{L}\p{N}_]/u', '', $tag))
            ->filter(fn (string $tag) => mb_strlen($tag) >= 2)
            ->map(fn (string $tag) => mb_strtolower($tag))
            ->unique()
            ->take(self::MAX_HASHTAGS)
            ->map(fn (string $tag) => '#'.$tag);

        return $tags->isNotEmpty() ? $tags->implode(' ') : null;
    }
}
