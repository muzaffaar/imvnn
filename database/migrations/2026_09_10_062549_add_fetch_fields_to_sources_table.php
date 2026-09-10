<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('fetch_type', 16)->default('rss')->after('type'); // App\Enums\SourceFetchType
            $table->text('source_url')->nullable()->after('fetch_type'); // feed URL (rss) or page to crawl (html_crawl)
            $table->boolean('is_active')->default(true)->after('media_reuse_permitted');
            $table->timestamp('last_fetched_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['fetch_type', 'source_url', 'is_active', 'last_fetched_at']);
        });
    }
};
