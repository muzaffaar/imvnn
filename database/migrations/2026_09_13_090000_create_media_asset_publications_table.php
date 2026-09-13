<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable "this exact picture has already gone out on this channel" ledger.
 *
 * `published_posts` answers "has this article been sent" and `media_assets.status`
 * answers "has this asset ever been used", but neither stops the same canonical
 * asset from being selected again for a *different*, unrelated article: `Published`
 * still counts as `isUsable()` (see `App\Enums\MediaStatus`), so a wire photo or
 * official press image reused across two unrelated stories was picked and sent to
 * the channel a second time under a second article's caption. This table lets
 * `MediaSelectionService` exclude anything already sent to a given channel from
 * that channel's future candidate pools, independent of which article it arrives
 * through.
 *
 * Keyed on the canonical media asset (see `MediaAsset::canonical()`), so a
 * duplicate row resolving onto an already-sent asset is excluded too, and per
 * channel, so a second channel starts with its own history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_asset_publications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('telegram_channel_id')->constrained()->cascadeOnDelete();
            $table->uuid('media_asset_id');
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->cascadeOnDelete();

            $table->timestamp('sent_at');
            $table->timestamps();

            // The guard itself: a unique index rather than a read-then-write check,
            // so two concurrent publish jobs cannot both conclude an asset is unsent.
            $table->unique(['telegram_channel_id', 'media_asset_id'], 'media_asset_publications_channel_asset_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_asset_publications');
    }
};
