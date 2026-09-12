<?php

namespace App\Models;

use App\Casts\StoredDateTime;
use App\Enums\MediaProvider;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVariantType;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\StoresDatesInStorageTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    use HasUuidPrimaryKey, StoresDatesInStorageTimezone;

    protected $fillable = [
        'type', 'status', 'provider', 'original_url', 'canonical_url', 'storage_path',
        'source_id', 'external_provider', 'external_id', 'mime_type', 'file_extension',
        'width', 'height', 'duration_seconds', 'file_size', 'content_hash', 'perceptual_hash',
        'caption', 'alt_text', 'relevance_score', 'quality_score', 'metadata',
        'duplicate_of_id', 'failure_reason', 'downloaded_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'status' => MediaStatus::class,
            'provider' => MediaProvider::class,
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
            'file_size' => 'integer',
            'relevance_score' => 'float',
            'quality_score' => 'float',
            'metadata' => 'array',
            'downloaded_at' => StoredDateTime::class,
            'processed_at' => StoredDateTime::class,
            'created_at' => StoredDateTime::class,
            'updated_at' => StoredDateTime::class,
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_id');
    }

    public function newsItems(): BelongsToMany
    {
        return $this->belongsToMany(NewsItem::class, 'news_media')
            ->using(NewsMedia::class)
            ->withPivot(['id', 'role', 'is_featured', 'position', 'caption_override', 'relevance_score'])
            ->withTimestamps();
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_media')
            ->using(EventMedia::class)
            ->withPivot(['id', 'is_featured', 'relevance_score', 'contributed_via'])
            ->withTimestamps();
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function metadataEntries(): HasMany
    {
        return $this->hasMany(MediaMetadataEntry::class);
    }

    public function processingLogs(): HasMany
    {
        return $this->hasMany(MediaProcessingLog::class);
    }

    public function variantOfType(MediaVariantType $type): ?MediaVariant
    {
        return $this->variants->firstWhere('variant_type', $type);
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable() && $this->duplicate_of_id === null;
    }

    /** The row selection/publishing should actually operate on — self, unless this is a resolved duplicate. */
    public function canonical(): self
    {
        return $this->duplicate_of_id ? ($this->duplicateOf ?? $this) : $this;
    }
}
