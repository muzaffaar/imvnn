<?php

namespace App\Services\Media;

use App\Models\NewsItem;
use Illuminate\Support\Str;

/**
 * Keeps an Event's shared media pool (event_media) in sync with the usable
 * media of every article clustered into it, so MediaSelectionService can
 * pick the best asset across the whole event — e.g. an official product shot
 * from one article illustrating a Telegram post generated from another.
 * See docs/MEDIA_ARCHITECTURE.md "Event media pool".
 */
class EventMediaPoolService
{
    public function syncFromNewsItem(NewsItem $newsItem): void
    {
        if (! $newsItem->event_id) {
            return;
        }

        foreach ($newsItem->mediaAssets as $asset) {
            if (! $asset->isUsable()) {
                continue;
            }

            $canonical = $asset->canonical();

            $newsItem->event->mediaAssets()->syncWithoutDetaching([
                $canonical->id => [
                    'id' => (string) Str::uuid(),
                    'relevance_score' => $asset->pivot->relevance_score,
                    'contributed_via' => 'article',
                ],
            ]);
        }
    }
}
