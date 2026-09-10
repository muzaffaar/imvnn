<?php

namespace App\Services\Media\Deduplication;

use App\Models\MediaAsset;

final class DuplicateMatch
{
    public function __construct(
        public readonly MediaAsset $canonical,
        public readonly int $level,      // 1 = URL, 2 = content hash, 3 = perceptual hash, 4 = embedding
        public readonly float $confidence, // 0..1
        public readonly string $reason,
    ) {}
}
