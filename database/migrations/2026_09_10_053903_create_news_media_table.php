<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Many-to-many: one article can have many media assets, and the same asset
     * (e.g. a wire-service photo) can be reused across many articles.
     */
    public function up(): void
    {
        Schema::create('news_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('news_item_id');
            $table->uuid('media_asset_id');

            $table->string('role', 32)->default('body'); // featured, body, gallery, thumbnail
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('position')->default(0); // order of appearance in the article

            // Per-article override of the asset's generic caption/alt text, and the
            // relevance score computed specifically for THIS article (see media_assets
            // comment on why this isn't only stored globally).
            $table->text('caption_override')->nullable();
            $table->float('relevance_score')->nullable();

            $table->timestamps();

            $table->foreign('news_item_id')->references('id')->on('news_items')->cascadeOnDelete();
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->cascadeOnDelete();

            $table->unique(['news_item_id', 'media_asset_id']);
            $table->index(['news_item_id', 'is_featured']);
            $table->index('media_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_media');
    }
};
