<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Existing channels created by telegram:channel stored the former 120
     * minute default explicitly. Move only that legacy value to the new 30
     * minute baseline; deliberately customized values are left untouched.
     */
    public function up(): void
    {
        DB::table('telegram_channels')
            ->select(['id', 'rules'])
            ->orderBy('id')
            ->each(function (object $channel): void {
                $rules = is_string($channel->rules) ? json_decode($channel->rules, true) : $channel->rules;

                if (! is_array($rules) || (int) ($rules['max_publish_interval_minutes'] ?? 30) !== 120) {
                    return;
                }

                $rules['max_publish_interval_minutes'] = 30;
                DB::table('telegram_channels')->where('id', $channel->id)->update([
                    'rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // The old 120-minute value may have been deliberately customized;
        // it cannot be restored safely without overwriting later changes.
    }
};
