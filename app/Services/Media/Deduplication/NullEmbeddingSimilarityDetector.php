<?php

namespace App\Services\Media\Deduplication;

use App\Models\MediaAsset;

/**
 * Default no-op binding for EmbeddingSimilarityDetectorInterface. Keeps the
 * pipeline runnable out of the box without an embedding model configured;
 * swap the binding in a service provider once one is available.
 */
class NullEmbeddingSimilarityDetector implements EmbeddingSimilarityDetectorInterface
{
    public function findSimilar(MediaAsset $asset, string $binaryContents): ?DuplicateMatch
    {
        return null;
    }
}
