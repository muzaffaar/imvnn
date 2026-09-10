<?php

namespace App\Console\Commands;

use App\Jobs\News\FetchNewsSourceJob;
use App\Models\Source;
use Illuminate\Console\Command;

class FetchNewsSourcesCommand extends Command
{
    protected $signature = 'news:fetch';

    protected $description = 'Dispatch a fetch job for every active news source';

    public function handle(): int
    {
        $sources = Source::where('is_active', true)->get();

        if ($sources->isEmpty()) {
            $this->warn('No active sources found — run `news-sources:sync` first.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            FetchNewsSourceJob::dispatch($source->id);
            $this->info("Dispatched fetch for: {$source->name}");
        }

        return self::SUCCESS;
    }
}
