<?php

namespace App\Services\Media\Storage;

use App\Enums\MediaVariantType;
use Illuminate\Support\Facades\Storage;

class MediaStorageService
{
    public function __construct(
        private readonly MediaStorageKeyBuilder $keyBuilder,
    ) {}

    private function disk()
    {
        return Storage::disk(config('media.disk'));
    }

    public function existsByPath(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    /**
     * Stores the original download under a content-hash key. If an object
     * with this hash already exists (this exact file was seen before, from
     * any article), skips the upload entirely and just returns the existing
     * key — this is the "avoid duplicate storage" rule from the architecture.
     */
    public function putOriginal(string $binaryContents, string $contentHash, string $extension): string
    {
        $key = $this->keyBuilder->original($contentHash, $extension);

        if (! $this->existsByPath($key)) {
            $this->disk()->put($key, $binaryContents, ['visibility' => 'public']);
        }

        return $key;
    }

    public function putVariant(string $binaryContents, MediaVariantType $variant, string $contentHash, string $extension): string
    {
        $key = $this->keyBuilder->variant($variant, $contentHash, $extension);

        if (! $this->existsByPath($key)) {
            $this->disk()->put($key, $binaryContents, ['visibility' => 'public']);
        }

        return $key;
    }

    public function get(string $path): ?string
    {
        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }
}
