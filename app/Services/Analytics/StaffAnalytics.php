<?php

namespace App\Services\Analytics;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/** Periods (preset or custom, in Baghdad time) and activity groups for the activity log. */
class StaffAnalytics
{
    public const TZ = 'Asia/Baghdad';

    /** Activity groups → the logged actions that count towards them. */
    public const CATEGORIES = [
        'exams_authoring' => ['exam.created', 'exam.updated', 'exam.deleted', 'exam.imported'],
        'exams_taking' => ['exam.started', 'exam.submitted'],
        'exams_marking' => ['exam.graded'],
        'accounts' => ['exam.students_imported'],
    ];

    public const PRESETS = [
        'today', 'shift', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month',
        'last_7', 'last_30', 'last_90', 'this_year', 'custom',
    ];

    /**
     * Bounds are in Baghdad time and `to` is exclusive.
     *
     * @return array{preset:string, from:CarbonImmutable, to:CarbonImmutable, prevFrom:CarbonImmutable, prevTo:CarbonImmutable, hourly:bool}
     */
    public function period(string $preset, ?string $from = null, ?string $to = null): array
    {
        $now = CarbonImmutable::now(self::TZ);
        $today = $now->startOfDay();
        // The Iraqi working week starts on Saturday.
        $weekStart = $today->subDays(($today->dayOfWeek + 1) % 7);

        [$start, $end] = match ($preset) {
            'shift' => [$this->shiftStart($today), $now],
            'yesterday' => [$today->subDay(), $today],
            'this_week' => [$weekStart, $today->addDay()],
            'last_week' => [$weekStart->subWeek(), $weekStart],
            'this_month' => [$today->startOfMonth(), $today->addDay()],
            'last_month' => [$today->startOfMonth()->subMonth(), $today->startOfMonth()],
            'last_7' => [$today->subDays(6), $today->addDay()],
            'last_30' => [$today->subDays(29), $today->addDay()],
            'last_90' => [$today->subDays(89), $today->addDay()],
            'this_year' => [$today->startOfYear(), $today->addDay()],
            'custom' => $this->customRange($from, $to, $today),
            default => [$today, $today->addDay()],
        };
        if ($preset === 'shift' && $end->lte($start)) {
            $end = $start->addMinute();
        }
        if (! in_array($preset, self::PRESETS, true)) {
            $preset = 'today';
        }

        $length = $start->diffInSeconds($end);

        return [
            'preset' => $preset,
            'from' => $start,
            'to' => $end,
            'prevFrom' => $start->subSeconds((int) $length),
            'prevTo' => $start,
            'hourly' => $length <= 86400 * 2,
        ];
    }

    public function shiftStartTime(): string
    {
        $value = (string) Setting::get('work_day_start', '08:00');

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : '08:00';
    }

    private function shiftStart(CarbonImmutable $today): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $this->shiftStartTime()));

        return $today->setTime($h, $m);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function customRange(?string $from, ?string $to, CarbonImmutable $today): array
    {
        try {
            $start = $from ? CarbonImmutable::parse($from, self::TZ)->startOfDay() : $today->subDays(29);
            $end = $to ? CarbonImmutable::parse($to, self::TZ)->startOfDay()->addDay() : $today->addDay();
        } catch (\Throwable) {
            return [$today->subDays(29), $today->addDay()];
        }
        if ($end->lte($start)) {
            [$start, $end] = [$end->subDay(), $start->addDay()];
        }
        // At most two years in one view.
        if ($start->diffInDays($end) > 731) {
            $start = $end->subDays(731);
        }

        return [$start, $end];
    }
}
