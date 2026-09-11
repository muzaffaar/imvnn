<?php

namespace App\Enums;

/**
 * Canonical queue names and their intended Supervisor worker pool.
 * Keep deploy/supervisor/imvnn.conf aligned with workerPools().
 */
enum QueueName: string
{
    case NewsFetch = 'news-fetch';
    case NewsParse = 'news-parse';
    case MediaExtraction = 'media-extraction';
    case MediaDownload = 'media-download';
    case MediaProcessing = 'media-processing';
    case ImageAnalysis = 'image-analysis';
    case MediaOptimization = 'media-optimization';
    case MediaSelection = 'media-selection';
    case VideoProcessing = 'video-processing';
    case TelegramPublishing = 'telegram-publishing';

    /** @return array<string, list<self>> */
    public static function workerPools(): array
    {
        return [
            'pipeline' => [
                self::NewsFetch,
                self::NewsParse,
                self::MediaExtraction,
                self::MediaDownload,
                self::MediaProcessing,
                self::ImageAnalysis,
                self::MediaOptimization,
                self::MediaSelection,
            ],
            'video' => [self::VideoProcessing],
            'telegram' => [self::TelegramPublishing],
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $queue) => $queue->value, self::cases());
    }

    public function workerPool(): string
    {
        foreach (self::workerPools() as $pool => $queues) {
            if (in_array($this, $queues, true)) {
                return $pool;
            }
        }

        throw new \LogicException("No worker pool is configured for queue {$this->value}.");
    }
}
