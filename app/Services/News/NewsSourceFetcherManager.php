<?php

namespace App\Services\News;

use App\Models\Source;
use Illuminate\Support\Collection;

class NewsSourceFetcherManager
{
    /** @param iterable<NewsSourceFetcherInterface> $fetchers */
    public function __construct(private readonly iterable $fetchers) {}

    /** @return Collection<int, \App\DTOs\RawArticleCandidate> */
    public function fetch(Source $source): Collection
    {
        foreach ($this->fetchers as $fetcher) {
            if ($fetcher->supports($source)) {
                return $fetcher->fetch($source);
            }
        }

        return collect();
    }
}
