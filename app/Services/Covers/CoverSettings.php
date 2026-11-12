<?php

namespace App\Services\Covers;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** «أغلفة الكتب» settings (2026-11-12): when the automatic search runs and where it looks. */
class CoverSettings
{
    public const TIMEZONE = 'Asia/Baghdad';

    public const DEFAULTS = [
        'covers_enabled' => '1',
        'covers_days' => '0,1,2,3,4,5,6',
        'covers_start' => '20:00',
        'covers_end' => '06:00',
        'covers_batch' => '40',           // files per run (a run every 10 minutes)
        'covers_kinds' => 'pdf,epub',
        'covers_min_pages' => '30',       // shorter files (lecture handouts) are not books
        'covers_title_search' => '1',     // without an ISBN, search by the file's name
        'covers_openlibrary' => '1',
        'covers_google' => '0',
        'covers_retry_days' => '30',
    ];

    public static function get(string $key): string
    {
        return (string) (Setting::get($key) ?? self::DEFAULTS[$key] ?? '');
    }

    public static function all(): array
    {
        return collect(self::DEFAULTS)->map(fn ($v, $k) => self::get($k))->all();
    }

    public static function on(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function int(string $key): int
    {
        return max(0, (int) self::get($key));
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_values(array_filter(array_map(fn ($k) => strtolower(trim($k)), explode(',', self::get('covers_kinds')))));
    }

    public static function googleKey(): ?string
    {
        $v = Setting::get('covers_google_key');
        if (blank($v)) {
            return null;
        }
        try {
            return Crypt::decryptString((string) $v);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<int> */
    public static function days(): array
    {
        return collect(explode(',', self::get('covers_days')))->map(fn ($d) => trim($d))
            ->filter(fn ($d) => $d !== '' && ctype_digit($d))->map(fn ($d) => (int) $d)->unique()->values()->all();
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm.':0'));

        return $h * 60 + $m;
    }

    /** Inside the hours the search may run? A window past midnight belongs to the day it starts on. */
    public static function inWindow(?Carbon $now = null): bool
    {
        $now = ($now ?? now())->copy()->setTimezone(self::TIMEZONE);
        $days = self::days();
        $start = self::minutes(self::get('covers_start'));
        $end = self::minutes(self::get('covers_end'));
        $m = $now->hour * 60 + $now->minute;
        if ($start === $end) {
            return in_array($now->dayOfWeek, $days, true);
        }
        if ($start < $end) {
            return in_array($now->dayOfWeek, $days, true) && $m >= $start && $m < $end;
        }
        if ($m >= $start) {
            return in_array($now->dayOfWeek, $days, true);
        }

        return $m < $end && in_array(($now->dayOfWeek + 6) % 7, $days, true);
    }

    public static function nextStart(?Carbon $now = null): ?Carbon
    {
        $t = ($now ?? now())->copy()->setTimezone(self::TIMEZONE)->second(0);
        for ($i = 0; $i < 8 * 24 * 6; $i++) {
            $t->addMinutes(10);
            if (self::inWindow($t)) {
                return $t;
            }
        }

        return null;
    }
}
