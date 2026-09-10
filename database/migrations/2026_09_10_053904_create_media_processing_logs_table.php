<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail of every pipeline stage attempt for an asset —
     * what to check first when a media asset silently never gets published.
     */
    public function up(): void
    {
        Schema::create('media_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_asset_id')->nullable(); // nullable: extraction can fail before an asset row even exists
            $table->uuid('news_item_id')->nullable();

            $table->string('stage', 32); // App\Enums\ProcessingStage
            $table->string('status', 16); // App\Enums\ProcessingLogStatus
            $table->text('message')->nullable();
            $table->jsonb('context')->default('{}'); // job id, http status, ffmpeg exit code, etc.
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);

            $table->timestamps();

            $table->foreign('media_asset_id')->references('id')->on('media_assets')->nullOnDelete();
            $table->foreign('news_item_id')->references('id')->on('news_items')->nullOnDelete();

            $table->index(['media_asset_id', 'stage']);
            $table->index(['stage', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_processing_logs');
    }
};
