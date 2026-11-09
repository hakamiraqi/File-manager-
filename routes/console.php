<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Offline server: a daily backup (does nothing unless BACKUP_DAILY is on).
Schedule::command('backup:run')->hourly();

// A hall server exchanges exams and results with the central one when it is online.
Schedule::command('exams:sync-central')->everyTenMinutes()->withoutOverlapping()->when(fn () => filled(\App\Models\Setting::get('central_url')));

// A standby exam server copies the main one every minute (does nothing on the main server).
Schedule::command('exams:mirror')->everyMinute()->withoutOverlapping()->when(fn () => filled(\App\Models\Setting::get('mirror_from')));

// A copy of each day's backup away from this server, encrypted (OffsiteBackup), and the server
// watching itself and alerting the admins' phones (ServerWatch).
Schedule::command('backup:offsite')->dailyAt('02:40')->withoutOverlapping()->when(fn () => \App\Services\Exams\OffsiteBackup::configured());
Schedule::command('exams:watch')->everyFiveMinutes()->withoutOverlapping();
