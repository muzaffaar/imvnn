<?php

namespace App\Services\Telegram;

use App\DTOs\PostMediaPlan;
use App\Models\NewsItem;
use Illuminate\Support\Str;

/**
 * Free, deterministic, always-succeeds fallback: title + excerpt, in
 * whatever language the article/analysis produced (not translated — that
 * needs Gemini, see GeminiCaptionComposer). No links: the post is meant to
 * stand on its own as a summary, not point back to a source URL.
 */
class PlainCaptionComposer implements CaptionComposerInterface
{
    public function compose(NewsItem $newsItem, PostMediaPlan $plan): string
    {
        $title = TelegramHtml::escape($newsItem->title);
        $excerpt = $this->excerpt($newsItem->content);

        $lines = ["📰 <b>{$title}</b>", ''];

        if ($excerpt) {
            $lines[] = $excerpt;
        }

        return trim(implode("\n", $lines));
    }

    private function excerpt(?string $content, int $maxLength = 500): ?string
    {
        if (! $content) {
            return null;
        }

        $plain = trim(strip_tags($content));

        return $plain ? TelegramHtml::escape(Str::limit($plain, $maxLength)) : null;
    }
}
