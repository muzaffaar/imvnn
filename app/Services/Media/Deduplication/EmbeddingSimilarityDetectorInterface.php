<?php

namespace App\Services\Media\Deduplication;

use App\Models\MediaAsset;

/**
 * Level 4 dedup: semantic similarity via image embeddings (e.g. CLIP), for
 * near-duplicates that survive resizing/recompression AND cropping/rotation/
 * watermarking — cases dHash (Level 3) misses. Deliberately not wired into the
 * default pipeline: it requires an embedding model/service and a vector index,
 * and is expensive enough that it should only run for a small, high-value
 * subset of assets. See docs/MEDIA_ARCHITECTURE.md "When to use embeddings".
 *
 * Enable by binding a real implementation in a service provider and flipping
 * media.deduplication.embedding_similarity_enabled.
 */
interface EmbeddingSimilarityDetectorInterface
{
    /** @return DuplicateMatch|null null if no sufficiently similar asset exists */
    public function findSimilar(MediaAsset $asset, string $binaryContents): ?DuplicateMatch;
}
