<?php

namespace Database\Seeders;

use App\Models\TelegramChannel;
use Illuminate\Database\Seeder;

/**
 * Registers the channel to publish into, from `services.telegram.channel`
 * (`TELEGRAM_CHANNEL_CHAT_ID` / `TELEGRAM_CHANNEL_NAME`).
 *
 * This is the non-interactive counterpart to `php artisan telegram:channel`.
 * The command additionally asks Telegram whether the chat exists and whether
 * the bot is an admin that can post, which is the better choice when a human
 * is running setup — a seeder can't verify any of that offline, so a typo'd
 * chat id seeds cleanly here and only surfaces later as a failed send.
 *
 * Deliberately non-destructive on an existing channel: it will not overwrite
 * `rules`, since those are tuned per channel after setup (posting cadence,
 * image limits), and re-running a seeder should never quietly reset them.
 */
class TelegramChannelSeeder extends Seeder
{
    public function run(): void
    {
        $chatId = config('services.telegram.channel.chat_id');

        if (blank($chatId)) {
            $this->command?->warn('TELEGRAM_CHANNEL_CHAT_ID is not set — skipping channel seeding.');
            $this->command?->line('Set it in .env, or register the channel with verification:');
            $this->command?->line('  php artisan telegram:channel @your_channel');

            return;
        }

        $chatId = (string) $chatId;
        $existing = TelegramChannel::where('chat_id', $chatId)->first();

        if ($existing) {
            $this->command?->info("Channel \"{$existing->name}\" already registered as id {$existing->id} — left untouched.");
            $this->command?->line("  php artisan publishing:start {$existing->id}");

            return;
        }

        $channel = TelegramChannel::create([
            'chat_id' => $chatId,
            'name' => config('services.telegram.channel.name') ?: $chatId,
            'is_active' => true,
            'rules' => TelegramChannel::defaultRules(),
        ]);

        $this->command?->info("Channel \"{$channel->name}\" seeded as id {$channel->id} (chat_id {$channel->chat_id}).");
        $this->command?->line('Publishing does not start on its own — run:');
        $this->command?->line("  php artisan publishing:start {$channel->id}");
    }
}
