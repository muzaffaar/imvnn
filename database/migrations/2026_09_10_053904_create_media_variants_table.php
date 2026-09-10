<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Derived renditions of a media_asset (thumbnail, telegram-optimized, ...).
     * Generated lazily — see MediaVariantGenerator — so this table is typically
     * much smaller than "one row per variant type per asset".
     */
    public function up(): void
    {
        Schema::create('media_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_asset_id');

            $table->string('variant_type', 32); // App\Enums\MediaVariantType
            $table->string('storage_path');
            $table->string('mime_type', 128)->nullable();
            $table->string('format', 16)->nullable(); // jpeg, webp, mp4, ...

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('hash', 64)->nullable();

            $table->timestamps();

            $table->foreign('media_asset_id')->references('id')->on('media_assets')->cascadeOnDelete();
            $table->unique(['media_asset_id', 'variant_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_variants');
    }
};
