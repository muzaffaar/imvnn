<?php

namespace App\Models;

use App\Casts\StoredDateTime;
use App\Models\Concerns\StoresDatesInStorageTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The durable answer to "has this exact picture already gone out on this
 * channel" — see the migration for why this exists alongside `PublishedPost`
 * and `media_assets.status`.
 */
class MediaAssetPublication extends Model
{
    use StoresDatesInStorageTimezone;

    protected $fillable = ['telegram_channel_id', 'media_asset_id', 'sent_at'];

    protected function casts(): array
    {
        return [
            'sent_at' => StoredDateTime::class,
            'created_at' => StoredDateTime::class,
            'updated_at' => StoredDateTime::class,
        ];
    }

    /** @return list<string> the subset of $mediaAssetIds already sent to this channel */
    public static function alreadySentAssetIds(int $telegramChannelId, array $mediaAssetIds): array
    {
        if ($mediaAssetIds === []) {
            return [];
        }

        return static::query()
            ->where('telegram_channel_id', $telegramChannelId)
            ->whereIn('media_asset_id', $mediaAssetIds)
            ->pluck('media_asset_id')
            ->all();
    }

    /**
     * Records a delivery. Idempotent: a duplicate is swallowed rather than
     * thrown, since this is called right after Telegram has accepted a post and
     * a fallback ladder can legitimately record the same canonical asset twice
     * (e.g. a retry after a delivery-outcome-unknown failure).
     */
    public static function record(int $telegramChannelId, string $mediaAssetId): void
    {
        try {
            static::create([
                'telegram_channel_id' => $telegramChannelId,
                'media_asset_id' => $mediaAssetId,
                'sent_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already recorded, which is the state we wanted.
        }
    }
}
