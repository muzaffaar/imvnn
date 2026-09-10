<?php

namespace App\Services\News;

use App\Models\Source;
use Illuminate\Support\Collection;

interface NewsSourceFetcherInterface
{
    public function supports(Source $source): bool;

    /** @return Collection<int, \App\DTOs\RawArticleCandidate> */
    public function fetch(Source $source): Collection;
}
