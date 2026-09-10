<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identifies the one publishing-scheduler chain that is allowed to run
     * for this channel. PublishNextReadyNewsItemJob carries the token it was
     * dispatched with and exits without rescheduling if it no longer
     * matches, so any forked chain dies within one cycle — see
     * docs/MEDIA_ARCHITECTURE.md "Publishing scheduler".
     */
    public function up(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->string('publish_chain_token', 64)->nullable()->after('rules');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropColumn('publish_chain_token');
        });
    }
};
