<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EventMedia extends Pivot
{
    use HasUuidPrimaryKey;

    protected $table = 'event_media';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'event_id', 'media_asset_id', 'is_featured', 'relevance_score', 'contributed_via',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'relevance_score' => 'float',
        ];
    }
}
