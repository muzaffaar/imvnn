<?php

namespace App\Providers;

use App\Services\Media\Deduplication\EmbeddingSimilarityDetectorInterface;
use App\Services\Media\Deduplication\NullEmbeddingSimilarityDetector;
use App\Services\Media\Extraction\Adapters\YoutubeAdapter;
use App\Services\Media\Extraction\ApiMediaExtractor;
use App\Services\Media\Extraction\HtmlContentExtractor;
use App\Services\Media\Extraction\HtmlMetadataExtractor;
use App\Services\Media\Extraction\MediaExtractionManager;
use App\Services\Media\Extraction\RssMediaExtractor;
use App\Services\Media\Scoring\NullVisionRelevanceAnalyzer;
use App\Services\Media\Scoring\VisionRelevanceAnalyzerInterface;
use App\Services\Telegram\CaptionComposerInterface;
use App\Services\Telegram\FallbackCaptionComposer;
use App\Services\Telegram\TelegramPublisher;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageManager::class, fn () => new ImageManager(new Driver));

        $this->app->when(TelegramPublisher::class)
            ->needs(Client::class)
            ->give(fn () => new Client([
                'base_uri' => rtrim(config('services.telegram.api_base_uri'), '/').'/',
                'timeout' => 15,
            ]));

        $this->app->bind(CaptionComposerInterface::class, FallbackCaptionComposer::class);

        $this->app->bind(EmbeddingSimilarityDetectorInterface::class, NullEmbeddingSimilarityDetector::class);
        $this->app->bind(
            VisionRelevanceAnalyzerInterface::class,
            NullVisionRelevanceAnalyzer::class,
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
