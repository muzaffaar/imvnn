<?php

namespace App\Jobs\News;

use App\Models\Source;
use App\Services\News\NewsSourceFetcherManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polls one source (RSS feed or HTML crawl target) for candidate articles
 * and fans a per-article parse/filter job out onto `news-parse` — so one
 * slow or broken article page can't hold up discovery of the rest, and a
 * slow source can't hold up any other source's fetch.
 */
class FetchNewsSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $sourceId)
    {
        $this->onQueue(config('news_sources.queues.fetch'));
    }

    public function handle(NewsSourceFetcherManager $fetcherManager): void
    {
        $source = Source::findOrFail($this->sourceId);

        if (! $source->is_active) {
            return;
        }

        $candidates = $fetcherManager->fetch($source);

        foreach ($candidates as $candidate) {
            ProcessNewsCandidateJob::dispatch($source->id, $candidate);
        }

        $source->update(['last_fetched_at' => now()]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("[news-fetch] source {$this->sourceId} failed: {$exception->getMessage()}");
    }
}
