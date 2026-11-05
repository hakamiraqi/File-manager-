<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

/**
 * Backups for the offline server (2026-11-05). Scheduled hourly: backs up once
 * a day when `backup.daily` is on (the offline package turns it on).
 * `--before-update`: the control panel runs it before migrating on every start;
 * it backs up only when there are migrations to run (a new version).
 * `--force`: back up now (the panel's button).
 */
class RunDatabaseBackup extends Command
{
    protected $signature = 'backup:run {--force : Back up now} {--before-update : Back up only if there are pending migrations}';

    protected $description = 'Create a backup of the database and uploaded files (daily, before an update, or now).';

    public function handle(DatabaseBackupService $backups, Migrator $migrator): int
    {
        if ($this->option('before-update')) {
            if (! $this->hasPendingMigrations($migrator)) {
                $this->info('No update to apply; skipping.');

                return self::SUCCESS;
            }

            return $this->backup($backups, 'before-update');
        }

        if ($this->option('force')) {
            return $this->backup($backups);
        }

        if (! config('backup.daily')) {
            $this->info('Daily backup is off; skipping.');

            return self::SUCCESS;
        }

        if (Setting::get('last_backup_date') === now()->toDateString()) {
            $this->info('A backup already ran today; skipping.');

            return self::SUCCESS;
        }

        return $this->backup($backups);
    }

    private function backup(DatabaseBackupService $backups, string $suffix = ''): int
    {
        $result = $backups->create($suffix);
        try {
            Setting::set('last_backup_date', now()->toDateString());
        } catch (\Throwable) {
            // No settings table yet (a fresh install before its migrations).
        }

        $this->info("Backup created: {$backups->dir()}".DIRECTORY_SEPARATOR.$result['filename']);

        return self::SUCCESS;
    }

    /** A fresh install (no migrations table yet) has nothing to lose. */
    private function hasPendingMigrations(Migrator $migrator): bool
    {
        if (! $migrator->repositoryExists()) {
            return false;
        }

        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));

        return array_diff(array_keys($files), $migrator->getRepository()->getRan()) !== [];
    }
}
