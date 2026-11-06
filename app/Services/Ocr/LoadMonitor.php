<?php

namespace App\Services\Ocr;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * How busy the computer is right now: processor use, free memory, free disk and the people using
 * the site. Text recognition only runs while all four are within the limits set by the admin.
 */
class LoadMonitor
{
    /** @return array{cpu: float|null, ram_free_mb: int|null, disk_free_gb: float|null, active_users: int} */
    public function sample(): array
    {
        [$cpu, $ram] = PHP_OS_FAMILY === 'Windows' ? $this->windows() : [$this->linuxCpu(), $this->linuxRam()];
        $disk = @disk_free_space(storage_path());

        return [
            'cpu' => $cpu === null ? null : round($cpu, 1),
            'ram_free_mb' => $ram,
            'disk_free_gb' => $disk === false ? null : round($disk / 1024 ** 3, 1),
            'active_users' => $this->activeUsers(),
        ];
    }

    /** Null when the load is fine, otherwise the reason (in Arabic, for the admin page). */
    public function busyReason(array $s): ?string
    {
        $maxCpu = OcrSettings::int('ocr_max_cpu');
        $minRam = OcrSettings::int('ocr_min_ram_mb');
        $minDisk = OcrSettings::int('ocr_min_disk_gb');
        $maxUsers = OcrSettings::int('ocr_max_users');

        return match (true) {
            $s['cpu'] !== null && $maxCpu > 0 && $s['cpu'] > $maxCpu => "المعالج مشغول ({$s['cpu']}% والحد {$maxCpu}%)",
            $s['ram_free_mb'] !== null && $minRam > 0 && $s['ram_free_mb'] < $minRam => "الذاكرة الحرة قليلة ({$s['ram_free_mb']} ميغا والحد {$minRam})",
            $s['disk_free_gb'] !== null && $minDisk > 0 && $s['disk_free_gb'] < $minDisk => "المساحة الحرة قليلة ({$s['disk_free_gb']} غيغا والحد {$minDisk})",
            $maxUsers > 0 && $s['active_users'] > $maxUsers => "عدد المستخدمين الآن {$s['active_users']} والحد {$maxUsers}",
            default => null,
        };
    }

    /** Average load of the last minute as a share of all cores. */
    private function linuxCpu(): ?float
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if ($load === false) {
            return null;
        }
        $cores = max(1, (int) preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo')));

        return min(100, $load[0] / $cores * 100);
    }

    private function linuxRam(): ?int
    {
        $info = @file_get_contents('/proc/meminfo');
        if ($info && preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $info, $m)) {
            return intdiv((int) $m[1], 1024);
        }

        return null;
    }

    /** @return array{0: float|null, 1: int|null} */
    private function windows(): array
    {
        try {
            $ps = '$c=(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average;'
                .'$m=(Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory; Write-Output "$c;$m"';
            $r = Process::timeout(30)->run(['powershell', '-NoProfile', '-NonInteractive', '-Command', $ps]);
            [$c, $m] = array_pad(explode(';', trim($r->output())), 2, '');

            return [is_numeric($c) ? (float) $c : null, is_numeric($m) ? intdiv((int) $m, 1024) : null];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /** People who did something on the site or the apps in the last 10 minutes. */
    private function activeUsers(): int
    {
        $since = now()->subMinutes(10);
        $ids = collect();
        try {
            $ids = $ids->merge(ActivityLog::where('created_at', '>=', $since)->whereNotNull('user_id')->distinct()->pluck('user_id'));
            if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
                $ids = $ids->merge(DB::table('sessions')->where('last_activity', '>=', $since->getTimestamp())->whereNotNull('user_id')->distinct()->pluck('user_id'));
            }
            if (Schema::hasTable('personal_access_tokens')) {
                $ids = $ids->merge(DB::table('personal_access_tokens')->where('last_used_at', '>=', $since)->distinct()->pluck('tokenable_id'));
            }
        } catch (Throwable) {
        }

        return $ids->unique()->count();
    }
}
