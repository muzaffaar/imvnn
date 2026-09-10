<?php

namespace App\Services\Telegram;

use App\DTOs\PostMediaPlan;
use App\Models\NewsItem;

interface CaptionComposerInterface
{
    public function compose(NewsItem $newsItem, PostMediaPlan $plan): string;
}
