<?php

namespace App\Providers;

use App\Services\Media\Extraction\Adapters\YoutubeAdapter;
use App\Services\Media\Extraction\ApiMediaExtractor;
use App\Services\Media\Extraction\HtmlContentExtractor;
use App\Services\Media\Extraction\HtmlMetadataExtractor;
use App\Services\Media\Extraction\MediaExtractionManager;
use App\Services\Media\Extraction\RssMediaExtractor;
use App\Services\Media\Deduplication\EmbeddingSimilarityDetectorInterface;
use App\Services\Media\Deduplication\NullEmbeddingSimilarityDetector;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageManager::class, fn () => new ImageManager(new Driver));

        $this->app->when(\App\Services\Telegram\TelegramPublisher::class)
            ->needs(\GuzzleHttp\Client::class)
            ->give(fn () => new \GuzzleHttp\Client([
                'base_uri' => rtrim(config('services.telegram.api_base_uri'), '/').'/',
                'timeout' => 15,
            ]));

        $this->app->bind(EmbeddingSimilarityDetectorInterface::class, NullEmbeddingSimilarityDetector::class);
        $this->app->bind(
            \App\Services\Media\Scoring\VisionRelevanceAnalyzerInterface::class,
            \App\Services\Media\Scoring\NullVisionRelevanceAnalyzer::class,
        );

        $this->app->singleton(MediaExtractionManager::class, function ($app) {
            return new MediaExtractionManager(
                extractors: [
                    // Order matters only for tie-breaking when merging duplicates —
                    // metadata (publisher-curated) is queried first, raw content scan last.
                    $app->make(HtmlMetadataExtractor::class),
                    $app->make(RssMediaExtractor::class),
                    $app->make(ApiMediaExtractor::class),
                    $app->make(HtmlContentExtractor::class),
                ],
                adapters: [
                    $app->make(YoutubeAdapter::class),
                ],
            );
        });
    }
}
