<?php

namespace App\Services\Telegram;

/**
 * Telegram's HTML parse_mode only requires `&`, `<`, `>` to be escaped in
 * plain text — unlike Laravel's `e()` (ENT_QUOTES), which also converts
 * straight quotes/apostrophes into &#039;/&quot;, showing up literally in
 * the message on some clients. Use this instead of `e()` for anything that
 * becomes Telegram message text.
 */
class TelegramHtml
{
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }
}
