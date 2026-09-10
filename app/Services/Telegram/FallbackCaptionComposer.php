<?php

namespace App\Services\Telegram;

use App\DTOs\PostMediaPlan;
use App\Models\NewsItem;
use Illuminate\Support\Facades\Log;

/**
 * What SelectMediaForPublishingJob actually depends on. Tries Gemini (for a
 * bilingual, emotionally-appropriate summary) only when configured, and
 * falls back to the free, single-language PlainCaptionComposer on any
 * failure — same never-break-the-pipeline principle as
 * App\Services\News\FallbackArticleAnalyzer.
 */
class FallbackCaptionComposer implements CaptionComposerInterface
{
    public function __construct(
        private readonly GeminiCaptionComposer $gemini,
        private readonly PlainCaptionComposer $plain,
    ) {}

    public function compose(NewsItem $newsItem, PostMediaPlan $plan): string
    {
        if ($this->geminiEnabled()) {
            try {
                return $this->gemini->compose($newsItem, $plan);
            } catch (\Throwable $e) {
                Log::warning("[telegram-caption] Gemini caption generation failed for news_item={$newsItem->id}: {$e->getMessage()}");

                // PlainCaptionComposer can only echo the article's own words,
                // which are in the source's language — usually English. When
                // the channel is meant to publish in specific languages,
                // posting the untranslated original is worse than not posting:
                // the publish attempt fails, retries, and the article is simply
                // left for the next attempt rather than going out wrong.
                if (! config('media.telegram_caption.fallback_to_original_language', false)) {
                    throw $e;
                }

                Log::warning("[telegram-caption] falling back to untranslated original for news_item={$newsItem->id}");
            }
        }

        return $this->plain->compose($newsItem, $plan);
    }

    private function geminiEnabled(): bool
    {
        return (bool) config('media.telegram_caption.gemini_enabled') && filled(config('services.gemini.api_key'));
    }
}
