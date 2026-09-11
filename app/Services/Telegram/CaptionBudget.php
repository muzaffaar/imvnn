<?php

namespace App\Services\Telegram;

use App\DTOs\PostMediaPlan;
use App\Enums\PostMediaType;

/**
 * Assembles a post within Telegram's length limits.
 *
 * Telegram allows 4096 characters for a plain sendMessage but only 1024 for
 * a photo/video/media-group *caption* — exceed it and the API rejects the
 * send outright (a real bilingual post ran to 1115 characters, which would
 * have failed every media attempt and silently degraded to text-only via
 * the fallback ladder). The limit applies to the visible text, so HTML tags
 * are excluded from the count here, with a margin because some clients
 * count astral-plane characters (emoji) as two.
 *
 * Shrinking is done by dropping whole optional pieces first and only then
 * trimming body text at a word boundary — never a blind substring of the
 * assembled string, which could cut through a <b> tag and break parsing.
 */
class CaptionBudget
{
    public const MEDIA_CAPTION_LIMIT = 1024;

    public const TEXT_MESSAGE_LIMIT = 4096;

    private const SAFETY_MARGIN = 24;

    public static function limitFor(PostMediaPlan $plan): int
    {
        return $plan->type === PostMediaType::None
            ? self::TEXT_MESSAGE_LIMIT
            : self::MEDIA_CAPTION_LIMIT;
    }

    /**
     * Shrinks in order of what's least missed: hashtags, then the humour
     * line, then the section bodies. The article link is required and stays
     * immediately before hashtags at the bottom of every post.
     *
     * The humour line is passed separately rather than appended to a body on
     * purpose — it carries its own <i> markup, and a body-level truncation
     * would happily cut through the middle of that tag, leaving unbalanced
     * HTML that Telegram rejects for the whole message. Anything with markup
     * is dropped whole or kept whole; only plain body text is ever trimmed.
     *
     * @param  list<array{flag?: string, title?: ?string, body: string}>  $sections
     */
    public static function assemble(
        ?string $header,
        array $sections,
        ?string $humorLine,
        string $articleLink,
        ?string $hashtags,
        int $limit,
    ): string {
        $budget = $limit - self::SAFETY_MARGIN;

        foreach ([[$humorLine, $hashtags], [$humorLine, null], [null, null]] as [$humor, $tags]) {
            $candidate = self::join($header, $sections, $humor, $articleLink, $tags);

            if (self::visibleLength($candidate) <= $budget) {
                return $candidate;
            }
        }

        // Then give each section an equal share of whatever the fixed parts
        // (header, flags, titles) leave behind.
        $overhead = self::visibleLength(self::join($header, self::withEmptyBodies($sections), null, $articleLink, null));
        $perSection = (int) floor(max(0, $budget - $overhead) / max(1, count($sections)));

        $trimmed = array_map(
            fn (array $section) => [...$section, 'body' => self::truncateAtWord($section['body'], $perSection)],
            $sections,
        );

        $result = self::join($header, $trimmed, null, $articleLink, null);

        // Degenerate case: titles alone blow the budget — drop them too.
        if (self::visibleLength($result) > $budget) {
            $result = self::join($header, array_map(
                fn (array $section) => [...$section, 'title' => null],
                $trimmed,
            ), null, $articleLink, null);
        }

        if (self::visibleLength($result) > $budget) {
            // Preserve the mandatory article link even if malformed upstream
            // title/body data consumes the entire caption budget.
            $result = $articleLink;
        }

        return $result;
    }

    /** @param list<array{flag?: string, title?: ?string, body: string}> $sections */
    private static function join(?string $header, array $sections, ?string $humorLine, string $articleLink, ?string $hashtags): string
    {
        $parts = [$header];

        foreach ($sections as $section) {
            $flag = $section['flag'] ?? null;
            $title = $section['title'] ?? null;
            $body = trim($section['body']);

            $block = trim(
                ($flag ? $flag.' ' : '')
                .($title ? "<b>{$title}</b>" : '')
                // Keep one visibly empty row between the bold title and the
                // summary, matching the channel's requested reading rhythm.
                .(($title && $body !== '') ? "\n\n" : '')
                .$body
            );

            $parts[] = $block !== '' ? $block : null;
        }

        $parts[] = $humorLine;
        $parts[] = $articleLink;
        $parts[] = $hashtags;

        return implode("\n\n", array_filter($parts, fn (?string $p) => $p !== null && $p !== ''));
    }

    /** @param list<array{flag?: string, title?: ?string, body: string}> $sections */
    private static function withEmptyBodies(array $sections): array
    {
        return array_map(fn (array $section) => [...$section, 'body' => ''], $sections);
    }

    /** Telegram's limit applies to the parsed text, so tags don't count toward it. */
    private static function visibleLength(string $html): int
    {
        return self::textLength(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function truncateAtWord(string $text, int $maxLength): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($maxLength <= 1 || self::textLength($text) <= $maxLength) {
            return $maxLength <= 1 ? '' : TelegramHtml::escape($text);
        }

        $cut = mb_substr($text, 0, $maxLength - 1);
        while (self::textLength($cut) > $maxLength - 1) {
            $cut = mb_substr($cut, 0, -1);
        }
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > $maxLength / 2) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return TelegramHtml::escape(rtrim($cut, " \t\n,;:—-").'…');
    }

    private static function textLength(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
