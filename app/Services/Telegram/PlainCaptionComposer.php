<?php

namespace App\Services\Telegram;

use App\DTOs\PostMediaPlan;
use App\Models\NewsItem;
use Illuminate\Support\Str;

/**
 * Free, deterministic, always-succeeds fallback: title + excerpt, in
 * whatever language the article/analysis produced (not translated — that
 * needs an AI provider, see AiCaptionComposer). PostHeader always supplies
 * the source link so this fallback remains traceable too.
 */
class PlainCaptionComposer implements CaptionComposerInterface
{
    public function compose(NewsItem $newsItem, PostMediaPlan $plan): string
    {
        return CaptionBudget::assemble(
            PostHeader::render($newsItem),
            [[
                'title' => TelegramHtml::escape($newsItem->title),
                'body' => $this->excerpt($newsItem->content) ?? '',
            ]],
            null,
            PostHeader::renderArticleLink($newsItem),
            null,
            CaptionBudget::limitFor($plan),
        );
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
