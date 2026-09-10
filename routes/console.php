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
