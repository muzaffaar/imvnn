<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The durable answer to "has this article already gone out on this channel".
 *
 * Distinct from `news_items.telegram_published_at`, which answers only "has
 * *this row* been sent" and therefore dies with the row. Re-syncing sources,
 * clearing stale articles or re-ingesting after a reset all produce fresh rows
 * with an empty publish history, and the scheduler will happily post them again
 * — which it did, sending three articles to the channel a second time.
 *
 * Keyed on the canonical URL because that is what identifies an article across
 * ingestions, and per channel so a second channel starts with its own history.
 */
class PublishedPost extends Model
{
    protected $fillable = [
        'telegram_channel_id', 'canonical_url', 'canonical_url_hash',
        'news_item_id', 'title', 'telegram_message_id', 'article_published_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_message_id' => 'integer',
            'article_published_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public static function hashFor(string $canonicalUrl): string
    {
        return hash('sha256', $canonicalUrl);
    }

    public static function alreadySent(int $telegramChannelId, ?string $canonicalUrl): bool
    {
        if (blank($canonicalUrl)) {
            return false;
        }

        return static::query()
            ->where('telegram_channel_id', $telegramChannelId)
            ->where('canonical_url_hash', static::hashFor($canonicalUrl))
            ->exists();
    }

    /**
     * Records a delivery. Idempotent: a duplicate is swallowed rather than
     * thrown, because this is called right after Telegram has accepted a post
     * and failing here would send the job down a retry path that could publish
     * the same article twice — the very thing this table exists to prevent.
     */
    public static function record(
        int $telegramChannelId,
        string $canonicalUrl,
        ?string $newsItemId = null,
        ?string $title = null,
        ?int $telegramMessageId = null,
        ?\DateTimeInterface $articlePublishedAt = null,
    ): void {
        try {
            static::create([
                'telegram_channel_id' => $telegramChannelId,
                'canonical_url' => $canonicalUrl,
                'canonical_url_hash' => static::hashFor($canonicalUrl),
                'news_item_id' => $newsItemId,
                'title' => $title,
                'telegram_message_id' => $telegramMessageId,
                'article_published_at' => $articlePublishedAt,
                'sent_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already recorded, which is the state we wanted.
        }
    }
}
