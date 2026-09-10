<?php

namespace App\Providers;

use App\Services\News\ArticleAnalyzerInterface;
use App\Services\News\FallbackArticleAnalyzer;
use App\Services\News\GeminiArticleAnalyzer;
use App\Services\News\HtmlCrawlSourceFetcher;
use App\Services\News\NewsSourceFetcherManager;
use App\Services\News\RssSourceFetcher;
use GuzzleHttp\Client;
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

        $this->app->bind(ArticleAnalyzerInterface::class, FallbackArticleAnalyzer::class);

        $this->app->when(GeminiArticleAnalyzer::class)
            ->needs(Client::class)
            ->give(fn () => new Client([
                'base_uri' => rtrim(config('services.gemini.api_base_uri'), '/').'/',
            ]));
    }
}
