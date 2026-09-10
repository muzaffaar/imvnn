<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Relations\Pivot;

class NewsMedia extends Pivot
{
    use HasUuidPrimaryKey;

    protected $table = 'news_media';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'news_item_id', 'media_asset_id', 'role', 'is_featured', 'position', 'caption_override', 'relevance_score',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'position' => 'integer',
            'relevance_score' => 'float',
        ];
    }
}
