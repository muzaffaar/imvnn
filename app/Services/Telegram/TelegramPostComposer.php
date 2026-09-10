<?php

namespace App\Services\Telegram;

use App\DTOs\PostMediaPlan;
use App\Enums\PostMediaType;
use App\Models\NewsItem;
use Illuminate\Support\Str;

/**
 * Builds the caption/message text. Kept separate from TelegramPublisher so
 * the text format (emoji header, excerpt length, link placement) can change
 * without touching anything that talks to the Telegram API.
 */
class TelegramPostComposer
{
    public function compose(NewsItem $newsItem, PostMediaPlan $plan): string
    {
        $title = e($newsItem->title);
        $excerpt = $this->excerpt($newsItem->content);

        $lines = ["📰 <b>{$title}</b>", ''];

        if ($excerpt) {
            $lines[] = $excerpt;
            $lines[] = '';
        }

        if ($plan->type === PostMediaType::VideoThumbnailFallback && $plan->fallbackLinkUrl) {
            $lines[] = '🎬 <a href="'.e($plan->fallbackLinkUrl).'">Watch the video</a>';
        }

        $lines[] = '🔗 <a href="'.e($newsItem->url).'">Read more</a>';

        return trim(implode("\n", $lines));
    }

    private function excerpt(?string $content, int $maxLength = 500): ?string
    {
        if (! $content) {
            return null;
        }

        $plain = trim(strip_tags($content));

        return $plain ? e(Str::limit($plain, $maxLength)) : null;
    }
}
