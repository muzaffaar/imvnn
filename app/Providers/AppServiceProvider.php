<?php

namespace App\Providers;

use App\Services\Http\BoundedHttpFetcher;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Shared plain HTTP client for BoundedHttpFetcher — used by both the
        // media pipeline (image/video downloads) and the news-fetching
        // pipeline (RSS/article pages). Distinct from TelegramPublisher's
        // client, which carries a base_uri (see MediaServiceProvider).
        $this->app->when(BoundedHttpFetcher::class)
            ->needs(Client::class)
            ->give(fn () => new Client);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
