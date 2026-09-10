<?php

namespace App\Services\Media\Storage;

use App\Enums\MediaVariantType;
use Carbon\CarbonImmutable;

/**
 * Builds object storage keys of the form:
 *
 *   media/{yyyy}/{mm}/original/{content_hash}.{ext}
 *   media/{yyyy}/{mm}/processed/{variant}/{content_hash}.{ext}
 *   media/{yyyy}/{mm}/thumbnails/{content_hash}.{ext}
 *
 * Deliberately NOT namespaced under news/{news_item_id}/: a media_assets row
 * can be reused across many articles (see news_media pivot), so the object
 * itself is addressed purely by content_hash. Keying by hash rather than a
 * random id also means re-extracting the same bytes for a different article
 * naturally resolves to the same object instead of storing it twice — see
 * MediaStorageService::existsByHash().
 */
class MediaStorageKeyBuilder
{
    public function original(string $contentHash, string $extension): string
    {
        return $this->prefix().'/original/'.$this->filename($contentHash, $extension);
    }

    public function variant(MediaVariantType $variant, string $contentHash, string $extension): string
    {
        $folder = $variant === MediaVariantType::Thumbnail ? 'thumbnails' : "processed/{$variant->value}";

        return $this->prefix().'/'.$folder.'/'.$this->filename($contentHash, $extension);
    }

    private function prefix(): string
    {
        $now = CarbonImmutable::now();

        return sprintf('media/%s/%s', $now->format('Y'), $now->format('m'));
    }

    private function filename(string $contentHash, string $extension): string
    {
        $extension = ltrim(strtolower($extension), '.') ?: 'bin';

        return "{$contentHash}.{$extension}";
    }
}
