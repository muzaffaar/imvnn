<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared media pool for an Event, aggregated from every article clustered
     * into it (see docs/MEDIA_ARCHITECTURE.md "Event Media Pool"). Publishing picks
     * from here rather than from a single article's media.
     */
    public function up(): void
    {
        Schema::create('event_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('media_asset_id');

            $table->boolean('is_featured')->default(false);
            $table->float('relevance_score')->nullable(); // relevance to the event as a whole, not one article
            $table->string('contributed_via', 32)->default('article'); // article, official_source, manual

            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->cascadeOnDelete();

            $table->unique(['event_id', 'media_asset_id']);
            $table->index(['event_id', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_media');
    }
};
