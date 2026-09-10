<?php

namespace App\Models;

use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaProcessingLog extends Model
{
    protected $fillable = [
        'media_asset_id', 'news_item_id', 'stage', 'status', 'message', 'context', 'duration_ms', 'attempt',
    ];

    protected function casts(): array
    {
        return [
            'stage' => ProcessingStage::class,
            'status' => ProcessingLogStatus::class,
            'context' => 'array',
            'duration_ms' => 'integer',
            'attempt' => 'integer',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }

    public static function record(
        ProcessingStage $stage,
        ProcessingLogStatus $status,
        ?string $mediaAssetId = null,
        ?string $newsItemId = null,
        ?string $message = null,
        array $context = [],
        ?int $durationMs = null,
        int $attempt = 1,
    ): self {
        return self::create([
            'media_asset_id' => $mediaAssetId,
            'news_item_id' => $newsItemId,
            'stage' => $stage,
            'status' => $status,
            'message' => $message,
            'context' => $context,
            'duration_ms' => $durationMs,
            'attempt' => $attempt,
        ]);
    }
}
