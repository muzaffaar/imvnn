<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramChannel extends Model
{
    protected $fillable = ['name', 'chat_id', 'is_active', 'rules', 'publish_chain_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rules' => 'array',
        ];
    }

    public function rule(string $key, mixed $default = null): mixed
    {
        return data_get($this->rules, $key, $default);
    }

    /**
     * Starting `rules` for a newly registered channel, shared by
     * TelegramChannelSeeder and the `telegram:channel` command so the two
     * setup paths can't drift into producing differently-behaving channels.
     *
     * Every key here is read back through rule() with its own fallback, so
     * an existing channel missing a key still behaves sensibly — this is
     * the initial payload, not the source of truth for defaults.
     */
    public static function defaultRules(array $overrides = []): array
    {
        return array_replace([
            // Baseline 2h between posts, dropping toward 15 min as a backlog
            // builds — see PublishNextReadyNewsItemJob.
            'min_publish_interval_minutes' => 15,
            'max_publish_interval_minutes' => 120,
            'publish_backlog_saturation_count' => 5,

            'max_images' => 4,
            'prefer_video' => true,
            'allow_media_group' => true,
            'min_quality_score' => 0.35,
            // Routine updates score zero. Keep only stories with at least one
            // material impact signal unless a channel deliberately overrides it.
            'min_news_priority_score' => 0.20,
        ], $overrides);
    }
}
