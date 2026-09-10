<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('chat_id'); // e.g. @channelname or -100xxxxxxxxxx
            $table->boolean('is_active')->default(true);
            // Channel-specific publishing rules consumed by MediaSelectionService, e.g.
            // {"max_images": 4, "prefer_video": true, "allow_media_group": true, "min_quality_score": 0.4}
            $table->jsonb('rules')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_channels');
    }
};
