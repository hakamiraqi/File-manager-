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

// Offline server: a daily backup (does nothing unless BACKUP_DAILY is on).
Schedule::command('backup:run')->hourly();

// Text recognition (OCR): a round every minute (it waits for the work hours and a quiet
// computer by itself), and a morning report for the admins.
Schedule::command('ocr:run')->everyMinute()->withoutOverlapping(120)->runInBackground();
Schedule::command('ocr:report')->dailyAt('07:30')->timezone('Asia/Baghdad');
// Book covers: every 10 minutes inside their hours (20:00–06:00 by default).
Schedule::command('covers:run')->everyTenMinutes()->withoutOverlapping(60)->runInBackground();
