<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('title');
            $table->string('url');
            $table->string('canonical_url')->nullable();
            $table->longText('raw_html')->nullable(); // input for MediaExtractor's HTML-based extractors
            $table->longText('content')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('canonical_url');
            $table->index(['event_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_items');
    }
};
