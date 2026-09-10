<?php

namespace App\Services\Media\Extraction\Adapters;

use App\DTOs\ExtractedMedia;

/**
 * A post-processing pass that recognizes a specific external platform's URLs
 * among already-extracted candidates and enriches them (external_id, canonical
 * embed URL, thumbnail, provider) so downstream stages never have to special-case
 * a platform themselves — see MediaIngestionPolicy, which uses external_provider
 * to decide "reference only, never download".
 */
interface PlatformAdapterInterface
{
    public function matches(string $url): bool;

    public function normalize(ExtractedMedia $media): ExtractedMedia;
}
