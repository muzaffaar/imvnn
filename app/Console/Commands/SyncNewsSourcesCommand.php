<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Support\Observability\PipelineLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Upserts config('news_sources.sources') into the sources table, by slug —
 * this is the bridge between "paste links in config/news_sources.php" and
 * the Source rows FetchNewsSourceJob actually operates on.
 */
class SyncNewsSourcesCommand extends Command
{
    protected $signature = 'news-sources:sync';

    protected $description = 'Sync config/news_sources.php into the sources table';

    public function handle(): int
    {
        $sources = config('news_sources.sources', []);

        if (empty($sources)) {
            $this->warn('config/news_sources.php has no sources configured yet — nothing to sync.');
            PipelineLogger::warning('news.source_sync_skipped', ['reason' => 'configuration_empty']);

            return self::SUCCESS;
        }

        $slugs = [];

        foreach ($sources as $entry) {
            $slug = $entry['slug'] ?? Str::slug($entry['name']);
            $slugs[] = $slug;

            Source::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $entry['name'],
                    'fetch_type' => $entry['fetch_type'],
                    'source_url' => $entry['url'],
                    'fetch_options' => $entry['fetch_options'] ?? [],
                    'type' => $entry['type'] ?? 'news_site',
                    'reliability_score' => $entry['reliability_score'] ?? 50,
                    'media_reuse_permitted' => $entry['media_reuse_permitted'] ?? false,
                    'is_active' => $entry['is_active'] ?? true,
                ],
            );

            $this->info("Synced: {$entry['name']} ({$slug})");
        }

        // A source removed from config (e.g. retired for editorial reasons)
        // must stop being fetched, not just stop being upserted — FetchNewsSourcesCommand
        // dispatches by `is_active`, so a row left active here would keep being
        // crawled forever with no config entry pointing at it.
        $deactivated = Source::whereNotIn('slug', $slugs)->where('is_active', true)->get();
        foreach ($deactivated as $source) {
            $source->update(['is_active' => false]);
            $this->info("Deactivated (no longer in config): {$source->name} ({$source->slug})");
        }

        PipelineLogger::info('news.source_sync_completed', [
            'source_count' => count($sources),
            'deactivated_count' => $deactivated->count(),
        ]);

        return self::SUCCESS;
    }
}
