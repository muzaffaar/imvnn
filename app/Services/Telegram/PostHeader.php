<?php

namespace App\Services\Telegram;

use App\Models\NewsItem;

/**
 * The bits of a post that don't depend on which composer produced the body:
 * a bold source line, the article's publish time, and topical hashtags.
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
        $timestamp = $newsItem->published_at ?? $newsItem->created_at;

        $lines = [];

        if ($source) {
            $lines[] = '📰 <b>'.TelegramHtml::escape($source).'</b>';
        }

        if ($timestamp) {
            $tz = config('media.telegram_caption.display_timezone');
            $lines[] = '🕒 '.$timestamp->copy()->setTimezone($tz)->format('d.m.Y · H:i');
        }

        return $lines ? implode("\n", $lines) : null;
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
            ->unique(fn (string $tag) => mb_strtolower($tag))
            ->take(self::MAX_HASHTAGS)
            ->map(fn (string $tag) => '#'.$tag);

        return $tags->isNotEmpty() ? $tags->implode(' ') : null;
    }
}
