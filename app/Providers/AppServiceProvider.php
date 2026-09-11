<?php

namespace App\Providers;

use App\Services\Http\BoundedHttpFetcher;
use App\Support\Observability\PipelineLogger;
use GuzzleHttp\Client;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** @var array<string, float> */
    private array $queueJobStartedAt = [];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Shared plain HTTP client for BoundedHttpFetcher — used by both the
        // media pipeline (image/video downloads) and the news-fetching
        // pipeline (RSS/article pages). Distinct from TelegramPublisher's
        // client, which carries a base_uri (see MediaServiceProvider).
        $this->app->when(BoundedHttpFetcher::class)
            ->needs(Client::class)
            ->give(fn () => new Client);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::before(function (JobProcessing $event): void {
            if (! $this->isApplicationJob($event->job)) {
                return;
            }

            $this->queueJobStartedAt[$this->queueJobKey($event->job)] = microtime(true);

            if (config('observability.queue_lifecycle')) {
                PipelineLogger::info('queue.job_started', $this->queueContext($event->job, $event->connectionName));
            }
        });

        Queue::after(function (JobProcessed $event): void {
            if (! $this->isApplicationJob($event->job)) {
                return;
            }

            $context = $this->completedQueueContext($event->job, $event->connectionName);

            if (config('observability.queue_lifecycle')) {
                PipelineLogger::info('queue.job_processed', $context);
            }
        });

        Queue::exceptionOccurred(function (JobExceptionOccurred $event): void {
            if ($this->isApplicationJob($event->job)) {
                PipelineLogger::exception(
                    'queue.job_retrying',
                    $event->exception,
                    $this->completedQueueContext($event->job, $event->connectionName, forgetStartTime: false),
                    'warning',
                );
            }
        });

        Queue::failing(function (JobFailed $event): void {
            if ($this->isApplicationJob($event->job)) {
                PipelineLogger::exception(
                    'queue.job_failed',
                    $event->exception,
                    $this->completedQueueContext($event->job, $event->connectionName),
                );
            }
        });
    }

    private function isApplicationJob(object $job): bool
    {
        return str_starts_with($job->resolveName(), 'App\\Jobs\\');
    }

    private function queueJobKey(object $job): string
    {
        return $job->getConnectionName().':'.$job->getJobId();
    }

    /** @return array<string, int|string|null> */
    private function queueContext(object $job, string $connectionName): array
    {
        return [
            'connection' => $connectionName,
            'queue' => $job->getQueue(),
            'job_id' => $job->getJobId(),
            'job_class' => $job->resolveName(),
            'attempt' => $job->attempts(),
        ];
    }

    /** @return array<string, int|string|null> */
    private function completedQueueContext(object $job, string $connectionName, bool $forgetStartTime = true): array
    {
        $context = $this->queueContext($job, $connectionName);
        $key = $this->queueJobKey($job);
        $startedAt = $this->queueJobStartedAt[$key] ?? null;

        if ($forgetStartTime) {
            unset($this->queueJobStartedAt[$key]);
        }

        if ($startedAt !== null) {
            $context['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        }

        return $context;
    }
}
