<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = [
        'name', 'slug', 'base_url', 'type', 'reliability_score', 'media_reuse_permitted',
    ];

    protected function casts(): array
    {
        return [
            'reliability_score' => 'integer',
            'media_reuse_permitted' => 'boolean',
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
