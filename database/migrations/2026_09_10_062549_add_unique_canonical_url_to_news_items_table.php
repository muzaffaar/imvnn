<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * News-level dedup: two sources (or a feed and a crawl of the same site)
     * reporting the same article should not create two NewsItem rows. NULLs
     * remain unconstrained, so existing rows without a canonical_url yet are
     * unaffected.
     */
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropIndex(['canonical_url']);
            $table->unique('canonical_url');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropUnique(['canonical_url']);
            $table->index('canonical_url');
        });
    }
};
