<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaMetadataEntry extends Model
{
    protected $table = 'media_metadata';

    protected $fillable = ['media_asset_id', 'key', 'value', 'extracted_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
