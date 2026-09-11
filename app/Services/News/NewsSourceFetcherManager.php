<?php

namespace App\Services\News;

use App\DTOs\RawArticleCandidate;
use App\Models\Source;
use App\Support\Observability\PipelineLogger;
use Illuminate\Support\Collection;

class NewsSourceFetcherManager
{
    /** @param iterable<NewsSourceFetcherInterface> $fetchers */
    public function __construct(private readonly iterable $fetchers) {}

    /** @return Collection<int, RawArticleCandidate> */
    public function fetch(Source $source): Collection
    {
        foreach ($this->fetchers as $fetcher) {
            if ($fetcher->supports($source)) {
                return $fetcher->fetch($source);
            }
        }

        PipelineLogger::error('news.fetcher_not_registered', [
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'fetch_type' => $source->fetch_type->value,
        ]);

        return collect();
    }
}
