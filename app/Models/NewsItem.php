<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NewsItem extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'source_id', 'event_id', 'title', 'url', 'canonical_url', 'raw_html', 'content', 'published_at',
        'media_analysis_completed_at', 'publish_queued_at', 'telegram_published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'media_analysis_completed_at' => 'datetime',
            'publish_queued_at' => 'datetime',
            'telegram_published_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function mediaAssets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'news_media')
            ->using(NewsMedia::class)
            ->withPivot(['id', 'role', 'is_featured', 'position', 'caption_override', 'relevance_score'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function featuredMedia(): BelongsToMany
    {
        return $this->mediaAssets()->wherePivot('is_featured', true);
    }
}
