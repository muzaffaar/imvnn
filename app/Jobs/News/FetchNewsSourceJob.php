<?php

namespace App\Jobs\News;

use App\Models\Source;
use App\Services\Http\BoundedHttpFetchException;
use App\Services\News\NewsSourceFetcherManager;
use App\Support\Observability\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
            PipelineLogger::info('news.fetch_skipped', [
                'source_id' => $source->id,
                'source_slug' => $source->slug,
                'reason' => 'source_inactive',
            ]);

            return;
        }

        PipelineLogger::info('news.fetch_started', [
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'fetch_type' => $source->fetch_type->value,
            'source_url' => PipelineLogger::url($source->source_url),
        ]);

        try {
            $candidates = $fetcherManager->fetch($source);
        } catch (BoundedHttpFetchException $e) {
            $context = [
                'source_id' => $source->id,
                'source_name' => $source->name,
                'source_slug' => $source->slug,
                'source_url' => $e->url ?? PipelineLogger::url($source->source_url),
                'http_status' => $e->statusCode,
            ];

            if (! $e->isRetryable()) {
                PipelineLogger::exception('news.fetch_permanent_http_error', $e, $context);
                $this->fail($e);

                return;
            }

            PipelineLogger::exception('news.fetch_transient_http_error', $e, $context, 'warning');

            throw $e;
        } catch (\InvalidArgumentException $e) {
            PipelineLogger::exception('news.fetch_invalid_http_options', $e, [
                'source_id' => $source->id,
                'source_name' => $source->name,
                'source_slug' => $source->slug,
                'source_url' => PipelineLogger::url($source->source_url),
            ]);
            $this->fail($e);

            return;
        }

        foreach ($candidates as $candidate) {
            ProcessNewsCandidateJob::dispatch($source->id, $candidate);
        }

        $source->update(['last_fetched_at' => now()]);

        PipelineLogger::info('news.fetch_completed', [
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'candidate_count' => $candidates->count(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $source = Source::find($this->sourceId);
        $httpException = $exception instanceof BoundedHttpFetchException ? $exception : null;

        PipelineLogger::exception('news.fetch_failed', $exception, [
            'source_id' => $this->sourceId,
            'source_name' => $source?->name,
            'source_slug' => $source?->slug,
            'source_url' => $httpException?->url ?? PipelineLogger::url($source?->source_url),
            'http_status' => $httpException?->statusCode,
        ]);
    }
}
