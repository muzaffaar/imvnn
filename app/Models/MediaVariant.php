<?php

namespace App\Models;

use App\Enums\MediaVariantType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaVariant extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'media_asset_id', 'variant_type', 'storage_path', 'mime_type', 'format',
        'width', 'height', 'file_size', 'hash',
    ];

    protected function casts(): array
    {
        return [
            'variant_type' => MediaVariantType::class,
            'width' => 'integer',
            'height' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
