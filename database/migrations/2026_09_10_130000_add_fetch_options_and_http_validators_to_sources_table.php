<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Per-source, non-secret fetch tuning: URL rules, XPath selectors,
            // pagination and prefilter policy. Kept with the source row so
            // queued jobs see the same rules that were synced from config.
            $table->json('fetch_options')->nullable()->after('source_url');

            // HTTP validators make feed polling cheap when the source returns
            // 304 Not Modified. Header strings are opaque and must not be parsed.
            $table->text('feed_etag')->nullable()->after('last_fetched_at');
            $table->string('feed_last_modified')->nullable()->after('feed_etag');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['fetch_options', 'feed_etag', 'feed_last_modified']);
        });
    }
};
