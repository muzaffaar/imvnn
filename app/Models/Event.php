<?php

namespace App\Models;

use App\Casts\StoredDateTime;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\StoresDatesInStorageTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasUuidPrimaryKey, StoresDatesInStorageTimezone;

    protected $fillable = ['title', 'slug', 'summary', 'first_seen_at'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => StoredDateTime::class,
            'created_at' => StoredDateTime::class,
            'updated_at' => StoredDateTime::class,
        ];
    }

    public function newsItems(): HasMany
    {
        return $this->hasMany(NewsItem::class);
    }

    /** The event's shared media pool, aggregated across every clustered article. */
    public function mediaAssets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'event_media')
            ->using(EventMedia::class)
            ->withPivot(['id', 'is_featured', 'relevance_score', 'contributed_via'])
            ->withTimestamps();
    }

    public function featuredMedia(): BelongsToMany
    {
        return $this->mediaAssets()->wherePivot('is_featured', true);
    }
}
