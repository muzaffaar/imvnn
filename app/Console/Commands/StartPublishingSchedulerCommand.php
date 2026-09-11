<?php

namespace App\Console\Commands;

use App\Jobs\Telegram\PublishNextReadyNewsItemJob;
use App\Models\TelegramChannel;
use App\Support\Observability\PipelineLogger;
use Illuminate\Console\Command;

/**
 * Starts the self-perpetuating publishing scheduler for a channel (see
 * PublishNextReadyNewsItemJob). Safe to run repeatedly: each run mints a
 * fresh chain token, and any chain still running under an older token
 * retires itself on its next run instead of posting alongside the new one.
 */
class StartPublishingSchedulerCommand extends Command
{
    protected $signature = 'publishing:start {channel : TelegramChannel id}';

    protected $description = 'Start the random-interval (15min-30min) publishing scheduler for a Telegram channel';

    public function handle(): int
    {
        $channel = TelegramChannel::find($this->argument('channel'));

        if (! $channel) {
            $this->error("No TelegramChannel with id {$this->argument('channel')}.");
            PipelineLogger::warning('telegram.scheduler_start_failed', [
                'telegram_channel_id' => (int) $this->argument('channel'),
                'reason' => 'channel_not_found',
            ]);

            return self::FAILURE;
        }

        PublishNextReadyNewsItemJob::startChain($channel);

        $min = $channel->rule('min_publish_interval_minutes', 15);
        $max = $channel->rule('max_publish_interval_minutes', 30);
        $this->info("Publishing scheduler started for '{$channel->name}' ({$max} min baseline, down to {$min} min when a backlog builds up).");
        $this->line('Any previously running chain for this channel will retire on its next run.');

        return self::SUCCESS;
    }
}
