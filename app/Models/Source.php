<?php

namespace App\Models;

use App\Enums\SourceFetchType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = [
        'name', 'slug', 'base_url', 'type', 'reliability_score', 'media_reuse_permitted',
        'fetch_type', 'source_url', 'fetch_options', 'is_active', 'last_fetched_at',
        'feed_etag', 'feed_last_modified',
    ];

    protected function casts(): array
    {
        return [
            'reliability_score' => 'integer',
            'media_reuse_permitted' => 'boolean',
            'fetch_type' => SourceFetchType::class,
            'fetch_options' => 'array',
            'is_active' => 'boolean',
            'last_fetched_at' => 'datetime',
        ];
    }

    public function newsItems(): HasMany
    {
        return $this->hasMany(NewsItem::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /** Normalized 0..1 reliability used directly in the quality-score formula. */
    public function reliabilityScoreNormalized(): float
    {
        return max(0, min(100, $this->reliability_score)) / 100;
    }
}
