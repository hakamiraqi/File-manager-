<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('filenest:purge-trash')->daily();
Schedule::command('filenest:purge-stale-uploads')->hourly();

// Library: reminders at 09:00 Baghdad time; set-aside copies not collected in time move on.
Schedule::command('library:remind')->dailyAt('09:00')->timezone('Asia/Baghdad');
Schedule::command('library:expire-holds')->everyFifteenMinutes();
