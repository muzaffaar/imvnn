<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs PublishNextReadyNewsItemJob's "what's eligible, what's already
     * spoken for" queries — see docs/NEWS_FETCHING.md "Publishing scheduler".
     *
     * Named `telegram_published_at`, not `published_at` — that column
     * already means the article's original publish date from its source.
     */
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            // Set by AnalyzeMediaForNewsItemJob once the media pipeline finishes —
            // an item isn't a publishing candidate before this is set.
            $table->timestamp('media_analysis_completed_at')->nullable()->after('content');

            // Set the instant PublishNextReadyNewsItemJob claims this item for a
            // publish attempt (atomically, to survive overlapping scheduler runs),
            // BEFORE SelectMediaForPublishingJob/PublishToTelegramJob actually run —
            // so the same article is never picked twice.
            $table->timestamp('publish_queued_at')->nullable()->after('media_analysis_completed_at');

            // Set once PublishToTelegramJob actually succeeds (any strategy,
            // including text-only).
            $table->timestamp('telegram_published_at')->nullable()->after('publish_queued_at');

            $table->index(
                ['telegram_published_at', 'publish_queued_at', 'media_analysis_completed_at'],
                'news_items_publishing_scheduler_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropIndex('news_items_publishing_scheduler_index');
            $table->dropColumn(['media_analysis_completed_at', 'publish_queued_at', 'telegram_published_at']);
        });
    }
};
