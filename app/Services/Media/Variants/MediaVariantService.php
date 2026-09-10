<?php

namespace App\Services\Media\Variants;

use App\Enums\MediaVariantType;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\Storage\MediaStorageService;

/**
 * Generates (if missing) and persists one named variant for an asset. Callers
 * — GenerateMediaVariantJob, MediaSelectionService — should always go through
 * ensure() rather than the raw generator so an already-generated variant is
 * never redundantly rebuilt or re-uploaded.
 */
class MediaVariantService
{
    public function __construct(
        private readonly ImageVariantGenerator $generator,
        private readonly MediaStorageService $storage,
    ) {}

    public function ensure(MediaAsset $asset, MediaVariantType $variantType): ?MediaVariant
    {
        $existing = $asset->variants->firstWhere('variant_type', $variantType)
            ?? $asset->variants()->where('variant_type', $variantType)->first();

        if ($existing) {
            return $existing;
        }

        if (! $asset->storage_path) {
            return null; // reference-only/embed assets have no bytes to derive a variant from
        }

        $original = $this->storage->get($asset->storage_path);
        if (! $original) {
            return null;
        }

        $encoded = match ($variantType) {
            MediaVariantType::Optimized => $this->generator->optimized($original),
            MediaVariantType::Thumbnail => $this->generator->thumbnail($original),
            MediaVariantType::Telegram => $this->generator->telegram($original),
            MediaVariantType::Compressed => $this->generator->compressed($original),
            MediaVariantType::Original => $original,
        };

        $hash = hash('sha256', $encoded);
        $extension = $variantType === MediaVariantType::Original ? $asset->file_extension : 'jpg';
        $path = $this->storage->putVariant($encoded, $variantType, $hash, $extension);

        [$width, $height] = $variantType === MediaVariantType::Original
            ? [$asset->width, $asset->height]
            : $this->generator->dimensions($encoded);

        return MediaVariant::create([
            'media_asset_id' => $asset->id,
            'variant_type' => $variantType,
            'storage_path' => $path,
            'mime_type' => 'image/jpeg',
            'format' => 'jpeg',
            'width' => $width,
            'height' => $height,
            'file_size' => strlen($encoded),
            'hash' => $hash,
        ]);
    }
}
