<?php

namespace App\Services\Telegram;

use App\DTOs\PostMediaPlan;
use App\Models\NewsItem;
use App\Support\Observability\PipelineLogger;

/**
 * What SelectMediaForPublishingJob actually depends on. Tries the configured
 * AI provider (for a
 * target-language, emotionally-appropriate summary) only when configured, and
 * falls back to the free, single-language PlainCaptionComposer on any
 * failure — same never-break-the-pipeline principle as
 * App\Services\News\FallbackArticleAnalyzer.
 */
class FallbackCaptionComposer implements CaptionComposerInterface
{
    public function __construct(
        private readonly AiCaptionComposer $ai,
        private readonly PlainCaptionComposer $plain,
    ) {}

    public function compose(NewsItem $newsItem, PostMediaPlan $plan): string
    {
        if ($this->aiEnabled()) {
            try {
                return $this->ai->compose($newsItem, $plan);
            } catch (\Throwable $e) {
                PipelineLogger::exception('telegram.caption_ai_failed', $e, ['news_item_id' => $newsItem->id], 'warning');

                // PlainCaptionComposer can only echo the article's own words,
                // which are in the source's language — usually English. When
                // the channel is meant to publish in specific languages,
                // posting the untranslated original is worse than not posting:
                // the publish attempt fails, retries, and the article is simply
                // left for the next attempt rather than going out wrong.
                if (! config('media.telegram_caption.fallback_to_original_language', false)) {
                    throw $e;
                }

                PipelineLogger::warning('telegram.caption_original_language_fallback', ['news_item_id' => $newsItem->id]);
            }
        }

        return $this->plain->compose($newsItem, $plan);
    }

    private function aiEnabled(): bool
    {
        return (bool) config('media.telegram_caption.ai_enabled')
            && (config('services.ai.provider') === 'openai-compatible' || filled(config('services.ai.api_key')));
    }
}
