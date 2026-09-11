<?php

namespace Tests\Feature;

use App\Enums\QueueName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_for_an_available_unreserved_job_on_a_managed_queue(): void
    {
        $timestamp = now()->subMinutes(10)->getTimestamp();
        DB::table('jobs')->insert([
            'queue' => QueueName::MediaSelection->value,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
        ]);

        $this->artisan('queue:health --stuck-after=60')
            ->expectsOutputToContain(QueueName::MediaSelection->value)
            ->assertExitCode(1);
    }

    public function test_it_ignores_delayed_jobs(): void
    {
        $timestamp = now()->addMinutes(10)->getTimestamp();
        DB::table('jobs')->insert([
            'queue' => QueueName::MediaSelection->value,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $timestamp,
            'created_at' => now()->getTimestamp(),
        ]);

        $this->artisan('queue:health --stuck-after=0')
            ->assertExitCode(0);
    }
}
