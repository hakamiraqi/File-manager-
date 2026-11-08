<?php

namespace App\Services\Ocr;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * When and how hard text recognition may work. Defaults follow the plan: outside office hours
 * (15:00 to 07:00 Baghdad time, every day), 20 files per round, and it steps aside when the
 * computer is busy.
 */
class OcrSettings
{
    public const TIMEZONE = 'Asia/Baghdad';

    public const DEFAULTS = [
        'ocr_enabled' => '1',
        'ocr_days' => '0,1,2,3,4,5,6',   // 0 = Sunday ... 6 = Saturday
        'ocr_start' => '15:00',
        'ocr_end' => '07:00',
        'ocr_all_day_days' => '5',       // Friday: any time
        'ocr_batch' => '20',
        'ocr_languages' => 'ara+eng',
        'ocr_max_cpu' => '70',
        'ocr_min_ram_mb' => '1536',
        'ocr_min_disk_gb' => '5',
        'ocr_max_users' => '10',
        'ocr_timeout_minutes' => '10',
        'ocr_max_pages' => '300',
        'ocr_max_mb' => '200',
        'ocr_report' => '1',
    ];

    public static function get(string $key): string
    {
        return (string) Setting::get($key, self::DEFAULTS[$key] ?? '');
    }

    public static function all(): array
    {
        return collect(self::DEFAULTS)->map(fn ($v, $k) => self::get($k))->all();
    }

    public static function enabled(): bool
    {
        return self::get('ocr_enabled') === '1';
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /** @return list<int> */
    public static function days(string $key = 'ocr_days'): array
    {
        return collect(explode(',', self::get($key)))->map(fn ($d) => trim($d))
            ->filter(fn ($d) => $d !== '' && ctype_digit($d))->map(fn ($d) => (int) $d)->unique()->values()->all();
    }

    /**
     * Is $now inside a work window? A window that crosses midnight (15:00 to 07:00) belongs to the
     * day it starts on, so Thursday 15:00 to Friday 07:00 counts as Thursday's.
     */
    public static function inWindow(?Carbon $now = null): bool
    {
        $now = ($now ?? now())->copy()->setTimezone(self::TIMEZONE);
        if (in_array($now->dayOfWeek, self::days('ocr_all_day_days'), true)) {
            return true;
        }

        $days = self::days();
        $start = self::minutes(self::get('ocr_start'));
        $end = self::minutes(self::get('ocr_end'));
        $m = $now->hour * 60 + $now->minute;

        if ($start === $end) {
            return in_array($now->dayOfWeek, $days, true); // whole day
        }
        if ($start < $end) {
            return in_array($now->dayOfWeek, $days, true) && $m >= $start && $m < $end;
        }
        // Crosses midnight.
        if ($m >= $start) {
            return in_array($now->dayOfWeek, $days, true);
        }
        if ($m < $end) {
            return in_array(($now->dayOfWeek + 6) % 7, $days, true);
        }

        return false;
    }

    /** The next time the window opens, for the status card. */
    public static function nextStart(?Carbon $now = null): ?Carbon
    {
        $t = ($now ?? now())->copy()->setTimezone(self::TIMEZONE)->second(0);
        $t->minute(intdiv($t->minute, 15) * 15)->addMinutes(15);
        for ($i = 0; $i < 8 * 24 * 4; $i++, $t->addMinutes(15)) {
            if (self::inWindow($t)) {
                return $t;
            }
        }

        return null;
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return ((int) $h % 24) * 60 + ((int) $m % 60);
    }
}
