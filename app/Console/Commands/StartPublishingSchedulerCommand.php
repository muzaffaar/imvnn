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
 *
 * The cadence options write the channel's `rules` before starting the chain,
 * so a posting rate is reproducible from the command line rather than a
 * hand-edited JSON column — and re-running with different numbers is how the
 * cadence is changed, since a running chain reads the rules afresh each pass.
 */
class StartPublishingSchedulerCommand extends Command
{
    protected $signature = 'publishing:start
        {channel : TelegramChannel id}
        {--min-interval= : Minutes to wait at most-urgent, i.e. with a full backlog}
        {--max-interval= : Minutes to wait with nothing else waiting (the baseline)}
        {--min-priority= : 0-1 news priority an article must reach to be published at all}';

    protected $description = 'Start the paced publishing scheduler for a Telegram channel';

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

        if (! $this->applyCadenceOptions($channel)) {
            return self::FAILURE;
        }

        PublishNextReadyNewsItemJob::startChain($channel);

        $min = $channel->rule('min_publish_interval_minutes', 15);
        $max = $channel->rule('max_publish_interval_minutes', 30);
        $priority = $channel->rule('min_news_priority_score', 0.20);

        $this->info($min === $max
            ? "Publishing scheduler started for '{$channel->name}' (every {$min} min)."
            : "Publishing scheduler started for '{$channel->name}' ({$max} min baseline, down to {$min} min when a backlog builds up).");
        $this->line("Minimum news priority to publish: {$priority}.");
        $this->line('Any previously running chain for this channel will retire on its next run.');

        return self::SUCCESS;
    }

    /** @return bool false when an option was rejected, leaving the channel untouched */
    private function applyCadenceOptions(TelegramChannel $channel): bool
    {
        $rules = is_array($channel->rules) ? $channel->rules : [];

        foreach (['min-interval' => 'min_publish_interval_minutes', 'max-interval' => 'max_publish_interval_minutes'] as $option => $rule) {
            $value = $this->option($option);

            if ($value === null) {
                continue;
            }

            if (! is_numeric($value) || (int) $value < 1) {
                $this->error("--{$option} must be a whole number of minutes, at least 1.");

                return false;
            }

            $rules[$rule] = (int) $value;
        }

        if (($priority = $this->option('min-priority')) !== null) {
            if (! is_numeric($priority) || (float) $priority < 0 || (float) $priority > 1) {
                $this->error('--min-priority must be between 0 and 1.');

                return false;
            }

            $rules['min_news_priority_score'] = (float) $priority;
        }

        // A floor above the baseline is incoherent rather than fatal: the
        // scheduler clamps the backlog case back up to the floor, but its
        // no-backlog branch returns the baseline unclamped, so an idle channel
        // would post *faster* than a busy one. Reject it here, where the
        // operator can see why, rather than in a queued job nobody is watching.
        $min = (int) ($rules['min_publish_interval_minutes'] ?? 15);
        $max = (int) ($rules['max_publish_interval_minutes'] ?? 30);

        if ($min > $max) {
            $this->error("--min-interval ({$min}) cannot exceed --max-interval ({$max}).");

            return false;
        }

        if ($rules !== $channel->rules) {
            $channel->forceFill(['rules' => $rules])->save();
        }

        return true;
    }
}
