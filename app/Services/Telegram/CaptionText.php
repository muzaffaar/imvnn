<?php

namespace App\Services\Telegram;

/**
 * Cleanup and sanity checks for model-written caption text, covering the
 * text-quality failure modes a generated post is actually prone to:
 * invisible/control characters, runaway emoji, ragged whitespace, a body
 * that came back nearly empty, and — the one that matters most for a
 * single-language channel — a reply that ignored the requested language.
 */
class CaptionText
{
    private const MAX_EMOJI = 3;

    private const MIN_BODY_LENGTH = 40;

    /**
     * Minimum share of letters that must belong to the expected script.
     * Deliberately lenient: real Russian AI coverage is full of Latin
     * product names ("GPT-5", "OpenAI", "Hugging Face"), so this is meant
     * to catch a reply that came back essentially in English, not to police
     * the occasional borrowed word.
     */
    private const MIN_SCRIPT_RATIO = 0.5;

    public static function sanitize(string $text): string
    {
        // Zero-width spaces, bidi overrides and stray control characters
        // render as nothing, as boxes, or as reordered text depending on the
        // client — none of which is ever intended.
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/[^\P{Cc}\n]/u', '', $text) ?? $text;

        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim(self::capEmoji($text));
    }

    /** Keeps the first few emoji and drops the rest, so a post can't turn into a sticker wall. */
    private static function capEmoji(string $text): string
    {
        $seen = 0;

        return preg_replace_callback(
            '/\p{Extended_Pictographic}(\x{FE0F}|\x{1F3FB}-\x{1F3FF})?/u',
            function (array $match) use (&$seen) {
                $seen++;

                return $seen <= self::MAX_EMOJI ? $match[0] : '';
            },
            $text,
        ) ?? $text;
    }

    public static function isLongEnough(string $text): bool
    {
        return mb_strlen(trim($text)) >= self::MIN_BODY_LENGTH;
    }

    /**
     * True when the text is predominantly written in the expected script.
     * `$script` is a Unicode script name, e.g. "Cyrillic" or "Latin".
     */
    public static function matchesScript(string $text, ?string $script): bool
    {
        if (! $script) {
            return true;
        }

        $expected = preg_match_all('/\p{'.$script.'}/u', $text) ?: 0;
        $letters = preg_match_all('/\p{L}/u', $text) ?: 0;

        if ($letters === 0) {
            return false;
        }

        return ($expected / $letters) >= self::MIN_SCRIPT_RATIO;
    }
}
