<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single physical/logical piece of media, independent of which article(s)
     * or event it is attached to (see news_media / event_media). One row per
     * distinct asset — reuse across articles is modeled through the pivots, not
     * by duplicating this row.
     */
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id');
            // Declared explicitly (not via the ->primary() column modifier) and
            // before the self-referencing duplicate_of_id foreign key below:
            // Postgres needs the primary key constraint to exist before it will
            // accept a FK pointing back at this same table, and Laravel queues
            // fluent column-modifier commands (like ->primary()) after explicit
            // ones, which would otherwise emit the FK before the PK.
            $table->primary('id');

            $table->string('type', 32)->index(); // App\Enums\MediaType
            $table->string('status', 32)->default('pending')->index(); // App\Enums\MediaStatus
            $table->string('provider', 32)->default('external'); // App\Enums\MediaProvider

            $table->text('original_url'); // exact URL first extracted
            $table->text('canonical_url')->nullable(); // normalized (scheme/query/tracking-params stripped) for Level 1 dedup
            $table->string('storage_path')->nullable(); // object storage key of the `original` variant; null unless provider=downloaded

            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->string('external_provider', 64)->nullable()->index(); // youtube, twitter, cdn, ...
            $table->string('external_id')->nullable(); // e.g. YouTube video id — stable across URL variants

            $table->string('mime_type', 128)->nullable();
            $table->string('file_extension', 16)->nullable();

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable(); // video/audio only

            $table->unsignedBigInteger('file_size')->nullable(); // bytes, null until downloaded/probed

            $table->char('content_hash', 64)->nullable(); // sha-256 of the downloaded bytes
            $table->string('perceptual_hash', 64)->nullable(); // hex dHash/pHash, images & video thumbnails only

            $table->text('caption')->nullable();
            $table->text('alt_text')->nullable();

            // Cached, context-independent scores. Per-article/per-event relevance and
            // "is this the featured image for THIS article" live on the pivot tables
            // (news_media/event_media) since the same asset can rank differently in
            // different contexts; these columns cache the best score seen so far so
            // duplicate/candidate lists can be sorted without joining every pivot.
            $table->float('relevance_score')->nullable();
            $table->float('quality_score')->nullable();

            // Small, hot-path metadata read on every selection pass (extractor used,
            // watermark_detected, video codec, og:site_name, ...). Larger/irregular
            // technical metadata (EXIF, full oEmbed payloads) goes in media_metadata
            // instead so this row stays cheap to load in bulk.
            $table->jsonb('metadata')->default('{}');

            $table->uuid('duplicate_of_id')->nullable(); // set when dedup resolves this row onto an earlier canonical asset
            $table->text('failure_reason')->nullable();

            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('duplicate_of_id')->references('id')->on('media_assets')->nullOnDelete();

            $table->unique('content_hash');
            $table->index('perceptual_hash');
            $table->index(['canonical_url', 'type']);
            $table->index('created_at');
            $table->unique(['external_provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
