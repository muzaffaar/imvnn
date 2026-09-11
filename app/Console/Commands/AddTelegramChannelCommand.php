<?php

namespace App\Console\Commands;

use App\Models\TelegramChannel;
use App\Support\Observability\PipelineLogger;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

/**
 * Registers (or updates) the Telegram channel to publish into, verifying
 * against the Bot API that the chat exists and that the bot can actually
 * post there — the two things that otherwise only surface as a failed send
 * hours later.
 *
 * Exists because a channel row is required for publishing but was otherwise
 * only creatable by hand, so a `migrate:fresh` left the pipeline with
 * nowhere to post and no documented way to fix it.
 */
class AddTelegramChannelCommand extends Command
{
    protected $signature = 'telegram:channel
        {chat : @username or numeric chat id of the channel}
        {--name= : Override the channel name (defaults to its Telegram title)}
        {--min-interval=15 : Minimum minutes between posts when a backlog exists}
        {--max-interval=120 : Baseline minutes between posts}
        {--max-images=4 : Maximum images in a media group}';

    protected $description = 'Register the Telegram channel to publish into, verifying bot access';

    public function handle(Client $client): int
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            $this->error('TELEGRAM_BOT_TOKEN is not set in .env.');
            PipelineLogger::warning('telegram.channel_registration_failed', ['reason' => 'bot_token_not_configured']);

            return self::FAILURE;
        }

        $chat = $this->call_($client, $token, 'getChat', ['chat_id' => $this->argument('chat')]);

        if (! $chat) {
            $this->error("Telegram could not find chat {$this->argument('chat')}.");
            $this->line('Check the @username, and make sure the bot has been added to the channel.');
            PipelineLogger::warning('telegram.channel_registration_failed', [
                'chat' => (string) $this->argument('chat'),
                'reason' => 'chat_not_accessible',
            ]);

            return self::FAILURE;
        }

        $bot = $this->call_($client, $token, 'getMe', []);
        $member = $bot ? $this->call_($client, $token, 'getChatMember', [
            'chat_id' => $chat['id'],
            'user_id' => $bot['id'],
        ]) : null;

        if (! $member || ! in_array($member['status'] ?? '', ['administrator', 'creator'], true)) {
            $this->error("Bot @{$bot['username']} is not an administrator of \"{$chat['title']}\".");
            $this->line('Add it as an admin with "Post messages" permission, then re-run this.');
            PipelineLogger::warning('telegram.channel_registration_failed', [
                'chat_id' => $chat['id'],
                'reason' => 'bot_not_administrator',
            ]);

            return self::FAILURE;
        }

        if (($member['can_post_messages'] ?? false) !== true && ($member['status'] ?? '') !== 'creator') {
            $this->error('Bot is an admin but lacks the "Post messages" permission.');
            PipelineLogger::warning('telegram.channel_registration_failed', [
                'chat_id' => $chat['id'],
                'reason' => 'bot_cannot_post_messages',
            ]);

            return self::FAILURE;
        }

        $channel = TelegramChannel::updateOrCreate(
            ['chat_id' => (string) $chat['id']],
            [
                'name' => $this->option('name') ?: ($chat['title'] ?? $this->argument('chat')),
                'is_active' => true,
                'rules' => TelegramChannel::defaultRules([
                    'min_publish_interval_minutes' => (int) $this->option('min-interval'),
                    'max_publish_interval_minutes' => (int) $this->option('max-interval'),
                    'max_images' => (int) $this->option('max-images'),
                ]),
            ],
        );

        $this->info("Channel \"{$channel->name}\" registered as id {$channel->id} (chat_id {$channel->chat_id}).");
        $this->line("Bot @{$bot['username']} confirmed as {$member['status']} with posting rights.");
        $this->newLine();
        $this->line("Next: php artisan publishing:start {$channel->id}");

        PipelineLogger::info('telegram.channel_registered', [
            'telegram_channel_id' => $channel->id,
            'chat_id' => $channel->chat_id,
            'channel_active' => $channel->is_active,
        ]);

        return self::SUCCESS;
    }

    private function call_(Client $client, string $token, string $method, array $params): ?array
    {
        $base = rtrim(config('services.telegram.api_base_uri'), '/');

        try {
            // Absolute URL, not a relative path against a base_uri: a bot
            // token contains a colon, which URI resolution reads as a scheme.
            $response = $client->post("{$base}/bot{$token}/{$method}", ['form_params' => $params]);
        } catch (\Throwable $e) {
            PipelineLogger::exception('telegram.channel_registration_api_failed', $e, ['method' => $method], 'warning');
            $this->warn("Telegram {$method} failed. Check the application log for the reason.");

            return null;
        }

        $body = json_decode((string) $response->getBody(), true);

        return $body['ok'] ?? false ? $body['result'] : null;
    }
}
