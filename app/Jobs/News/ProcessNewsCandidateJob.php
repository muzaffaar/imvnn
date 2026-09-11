<?php

namespace App\Jobs\News;

use App\DTOs\RawArticleCandidate;
use App\Models\Source;
use App\Services\News\NewsIngestionService;
use App\Support\Observability\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Filters, fetches (if needed), parses, and — if it survives all of that —
 * creates a NewsItem for one candidate article, then hands off into the
 * media pipeline (ExtractMediaJob). One candidate per job so a single
 * unreachable/broken article page never affects its siblings.
 */
class ProcessNewsCandidateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [15, 60, 300];

    public function __construct(
        public readonly int $sourceId,
        public readonly RawArticleCandidate $candidate,
    ) {
        $this->onQueue(config('news_sources.queues.parse'));
    }

    public function handle(NewsIngestionService $ingestionService): void
    {
        $source = Source::findOrFail($this->sourceId);

        $newsItem = $ingestionService->ingest($source, $this->candidate);

        if ($newsItem) {
            $ingestionService->dispatchMediaExtraction($newsItem);
        }
    }

    public function failed(Throwable $exception): void
    {
        PipelineLogger::exception('news.candidate_failed', $exception, [
            'source_id' => $this->sourceId,
            'article_url' => PipelineLogger::url($this->candidate->url),
        ]);
    }
}
