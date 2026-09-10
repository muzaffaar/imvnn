<?php

namespace App\Console\Commands;

use App\Jobs\Telegram\PublishNextReadyNewsItemJob;
use App\Models\TelegramChannel;
use Illuminate\Console\Command;

/**
 * Starts the self-perpetuating publishing scheduler for a channel (see
 * PublishNextReadyNewsItemJob). Run this ONCE per channel — running it again
 * while a chain is already active starts a second, independent chain
 * running in parallel (each one still only ever claims a given article
 * exactly once, so the effect is just "posts roughly twice as often," not
 * duplicate posts of the same article).
 */
class StartPublishingSchedulerCommand extends Command
{
    protected $signature = 'publishing:start {channel : TelegramChannel id}';

    protected $description = 'Start the random-interval (15min-2h) publishing scheduler for a Telegram channel';

    public function handle(): int
    {
        $channel = TelegramChannel::find($this->argument('channel'));

        if (! $channel) {
            $this->error("No TelegramChannel with id {$this->argument('channel')}.");

            return self::FAILURE;
        }

        PublishNextReadyNewsItemJob::dispatch($channel->id);

        $min = $channel->rule('min_publish_interval_minutes', 15);
        $max = $channel->rule('max_publish_interval_minutes', 120);
        $this->info("Publishing scheduler started for '{$channel->name}' (random {$min}-{$max} min between posts).");

        return self::SUCCESS;
    }
}
