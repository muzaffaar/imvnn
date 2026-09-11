<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A durable record of what has actually been sent to each channel, keyed by the
 * article's canonical URL rather than by a `news_items` row.
 *
 * `news_items.telegram_published_at` was the only guard, and it is tied to the
 * life of that row. Anything that rebuilds the articles table — re-syncing
 * sources, clearing stale rows, re-ingesting after a reset — produces fresh rows
 * with an empty publish history, and the scheduler happily posts them again.
 * That is exactly what happened: three articles already in the channel were
 * re-ingested and sent a second time.
 *
 * The canonical URL is the right key because it is what identifies an article
 * across ingestions (`UrlNormalizer`, and the unique index on
 * `news_items.canonical_url`). Keyed per channel as well, so adding a second
 * channel does not inherit the first one's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('published_posts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('telegram_channel_id')->constrained()->cascadeOnDelete();
            $table->string('canonical_url', 2048);

            // The indexed identity. Postgres caps a btree entry at roughly 2704
            // bytes, which a 2048-character URL can exceed once combined with
            // the channel id, so the unique index is built on a digest instead.
            $table->char('canonical_url_hash', 64);

            // Informational: enough to audit the channel by eye without joining
            // back to an articles row that may no longer exist.
            $table->uuid('news_item_id')->nullable();
            $table->string('title')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->timestamp('article_published_at')->nullable();
            $table->timestamp('sent_at');

            $table->timestamps();

            // The guard itself: a unique index rather than a read-then-write
            // check, so two concurrent workers cannot both conclude it is unsent.
            $table->unique(['telegram_channel_id', 'canonical_url_hash'], 'published_posts_channel_url_unique');
            $table->index('sent_at');
        });

        $this->backfillFromSentArticles();
    }

    /**
     * Imports the history the new table is supposed to already know about.
     *
     * Without this, an existing installation starts with an empty ledger, every
     * article it has ever sent looks unsent, and the freshness window is the
     * only thing standing between it and reposting the day's news.
     *
     * `news_items` carries publishing state globally rather than per channel
     * (see docs/BOT_RELIABILITY_AUDIT.md "Remaining gaps"), so a sent article is
     * attributed to every channel. That is exact for a single-channel deployment
     * and deliberately conservative for several: the worst case is suppressing a
     * post on a channel that never received it, which beats sending a duplicate.
     */
    private function backfillFromSentArticles(): void
    {
        $channelIds = DB::table('telegram_channels')->pluck('id');

        if ($channelIds->isEmpty()) {
            return;
        }

        DB::table('news_items')
            ->whereNotNull('telegram_published_at')
            ->whereNotNull('canonical_url')
            ->orderBy('telegram_published_at')
            ->select('id', 'title', 'canonical_url', 'published_at', 'telegram_published_at')
            ->chunk(200, function ($articles) use ($channelIds) {
                $rows = [];

                foreach ($articles as $article) {
                    foreach ($channelIds as $channelId) {
                        $rows[] = [
                            'telegram_channel_id' => $channelId,
                            'canonical_url' => $article->canonical_url,
                            'canonical_url_hash' => hash('sha256', $article->canonical_url),
                            'news_item_id' => $article->id,
                            'title' => $article->title,
                            'telegram_message_id' => null,
                            'article_published_at' => $article->published_at,
                            'sent_at' => $article->telegram_published_at,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('published_posts')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_posts');
    }
};
