<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            // Transport-specific discovery data retained for downstream
            // extractors. RSS media must survive the queue boundary.
            $table->json('source_payload')->nullable()->after('raw_html');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn('source_payload');
        });
    }
};
