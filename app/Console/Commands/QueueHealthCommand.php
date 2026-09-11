<?php

namespace App\Console\Commands;

use App\Enums\QueueName;
use App\Support\Observability\PipelineLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueHealthCommand extends Command
{
    protected $signature = 'queue:health
        {--stuck-after=300 : Seconds an available, unreserved job may wait before the command fails}';

    protected $description = 'Report configured pipeline queues with jobs waiting for a Supervisor worker';

    public function handle(): int
    {
        $stuckAfter = max(0, (int) $this->option('stuck-after'));
        $cutoff = now()->subSeconds($stuckAfter)->getTimestamp();

        $stuckCounts = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as count, MAX(attempts) as max_attempts, MIN(available_at) as oldest_available_at')
            ->whereIn('queue', QueueName::values())
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $cutoff)
            ->groupBy('queue')
            ->pluck('count', 'queue');

        $rows = [];
        foreach (QueueName::cases() as $queue) {
            $rows[] = [
                'queue' => $queue->value,
                'worker_pool' => $queue->workerPool(),
                'ready_unreserved_jobs' => (int) ($stuckCounts[$queue->value] ?? 0),
            ];
        }

        $this->table(['Queue', 'Expected worker pool', "Ready/unreserved for >= {$stuckAfter}s"], $rows);

        if ($stuckCounts->isNotEmpty()) {
            PipelineLogger::error('queue.health_stuck', [
                'stuck_after_seconds' => $stuckAfter,
                'ready_unreserved_job_counts' => $stuckCounts->map(fn ($count) => (int) $count)->all(),
            ]);
            $this->error('One or more configured queues have jobs ready but unreserved. Check Supervisor status and the worker queue list.');

            return self::FAILURE;
        }

        $this->info('All configured queues are either empty, reserved, or intentionally delayed.');

        return self::SUCCESS;
    }
}
