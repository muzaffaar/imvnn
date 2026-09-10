<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extensible key/value technical metadata (EXIF, oEmbed payloads, ffprobe
     * streams, Open Graph tags as originally found, etc). Kept out of media_assets
     * so that adding a new metadata source never requires a migration, and so
     * bulk asset queries don't have to load large/irregular blobs.
     */
    public function up(): void
    {
        Schema::create('media_metadata', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_asset_id');
            $table->string('key', 128); // e.g. "exif", "oembed", "ffprobe", "og_tags"
            $table->jsonb('value');
            $table->string('extracted_by', 64)->nullable(); // which extractor/analyzer wrote this
            $table->timestamps();

            $table->foreign('media_asset_id')->references('id')->on('media_assets')->cascadeOnDelete();
            $table->unique(['media_asset_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_metadata');
    }
};
