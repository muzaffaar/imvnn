<?php

namespace App\DTOs;

use App\Enums\PostMediaType;
use App\Models\MediaAsset;

final class PostMediaPlan
{
    /** @param list<MediaAsset> $assets ranked best-first; publishing falls back down the list on failure */
    public function __construct(
        public readonly PostMediaType $type,
        public readonly array $assets = [],
        public readonly ?string $fallbackLinkUrl = null, // e.g. original video URL when only its thumbnail is posted
    ) {}

    public static function none(): self
    {
        return new self(PostMediaType::None);
    }
}
