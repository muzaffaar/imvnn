<?php

namespace App\Providers;

use App\Services\News\HtmlCrawlSourceFetcher;
use App\Services\News\NewsSourceFetcherManager;
use App\Services\News\RssSourceFetcher;
use Illuminate\Support\ServiceProvider;

class NewsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NewsSourceFetcherManager::class, function ($app) {
            return new NewsSourceFetcherManager([
                $app->make(RssSourceFetcher::class),
                $app->make(HtmlCrawlSourceFetcher::class),
            ]);
        });
    }
}
