<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$newsFetchIntervalMinutes = config('news_sources.fetch_interval_minutes', 30);

Schedule::command('news:fetch')
    ->cron("*/{$newsFetchIntervalMinutes} * * * *")
    ->withoutOverlapping();

// Makes a stopped/misconfigured Supervisor worker visible in the scheduler
// log and Laravel application log instead of letting an available queue grow
// unnoticed. Delayed jobs and jobs currently reserved by workers are ignored.
Schedule::command('queue:health --stuck-after=300')
    ->everyFiveMinutes()
    ->withoutOverlapping();
