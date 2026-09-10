<?php

namespace App\Services\Media\Extraction;

use App\DTOs\ExtractionContext;
use Illuminate\Support\Collection;

interface MediaExtractorInterface
{
    /** Cheap check — does this context even have the input this extractor needs? */
    public function supports(ExtractionContext $context): bool;

    /** @return Collection<int, \App\DTOs\ExtractedMedia> */
    public function extract(ExtractionContext $context): Collection;
}
