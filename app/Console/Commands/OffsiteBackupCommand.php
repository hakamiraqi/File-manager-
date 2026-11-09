<?php

namespace App\Console\Commands;

use App\Services\Exams\OffsiteBackup;
use Illuminate\Console\Command;

/** The daily copy of the backup on outside storage, or (--decrypt) opening a downloaded copy. */
class OffsiteBackupCommand extends Command
{
    protected $signature = 'backup:offsite {--decrypt= : A downloaded .zip.enc file to open} {--pass= : Its passphrase (asked if left out)}';

    protected $description = 'Send an encrypted backup to the off-site storage (S3-compatible), or decrypt a downloaded one.';

    public function handle(OffsiteBackup $offsite): int
    {
        if ($file = $this->option('decrypt')) {
            if (! is_file($file)) {
                $this->error("No such file: {$file}");

                return self::FAILURE;
            }
            $pass = $this->option('pass') ?: (string) $this->secret('Passphrase');
            $out = preg_replace('/\.enc$/', '', $file);
            $out = $out === $file ? $file.'.zip' : $out;
            file_put_contents($out, OffsiteBackup::decrypt((string) file_get_contents($file), $pass));
            $this->info("Opened: {$out} (restore it with php artisan backup:restore)");

            return self::SUCCESS;
        }
        if (! OffsiteBackup::configured()) {
            $this->info('Off-site backup is not set up; skipping.');

            return self::SUCCESS;
        }
        $r = $offsite->run();
        if (! $r['ok']) {
            $this->error('Off-site backup failed: '.$r['error']);

            return self::FAILURE;
        }
        $this->info("Sent {$r['file']} (".round($r['size'] / 1048576, 1)." MB); removed {$r['pruned']} old copies.");

        return self::SUCCESS;
    }
}
