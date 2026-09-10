<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal stand-in for the broader news pipeline's source registry.
     * Only the columns the media pipeline actually reads (reliability, attribution)
     * are modeled here; a real ingestion system would own this table.
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('base_url')->nullable();
            $table->string('type')->default('news_site'); // news_site, official, social, wire_service
            $table->unsignedTinyInteger('reliability_score')->default(50); // 0-100, feeds source_reliability scoring
            $table->boolean('media_reuse_permitted')->default(false); // licensing: may we copy their media bytes?
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
