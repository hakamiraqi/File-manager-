<?php

namespace App\Services\Library;

use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * Lending rules per reader type, editable on the library settings page. Defaults follow
 * the plan: students 14 days / 3 books / 1 renewal / 250 IQD a day; teachers 30 / 10 / 3 / none.
 */
class LibraryRules
{
    public const DEFAULTS = [
        'student' => ['loan_days' => 14, 'max_loans' => 3, 'renewals' => 1, 'fine_per_day' => 250],
        'teacher' => ['loan_days' => 30, 'max_loans' => 10, 'renewals' => 3, 'fine_per_day' => 0],
        'staff' => ['loan_days' => 14, 'max_loans' => 5, 'renewals' => 1, 'fine_per_day' => 250],
    ];

    public const GENERAL = ['hold_hours' => 48, 'remind_days_before' => 3, 'remind_every_days' => 3, 'lost_after_days' => 60];

    /** @return array{loan_days:int, max_loans:int, renewals:int, fine_per_day:int} */
    public function for(string $type): array
    {
        $base = self::DEFAULTS[$type] ?? self::DEFAULTS['student'];
        $out = [];
        foreach ($base as $key => $default) {
            $out[$key] = (int) Setting::get("library_{$type}_{$key}", $default);
        }

        return $out;
    }

    public function general(string $key): int
    {
        return (int) Setting::get("library_{$key}", self::GENERAL[$key]);
    }

    /** All rules, for the settings page. */
    public function all(): array
    {
        $types = [];
        foreach (array_keys(self::DEFAULTS) as $type) {
            $types[$type] = $this->for($type);
        }
        $general = [];
        foreach (array_keys(self::GENERAL) as $key) {
            $general[$key] = $this->general($key);
        }

        return ['types' => $types, 'general' => $general];
    }

    /** Due date: N days from now, moved past holidays (step 3), at the end of that day. */
    public function dueDate(string $type, ?CarbonInterface $from = null): CarbonInterface
    {
        $due = ($from ?? now())->copy()->addDays($this->for($type)['loan_days']);
        $holidays = $this->holidays();
        for ($i = 0; $i < 60 && $this->isClosed($due, $holidays); $i++) {
            $due = $due->addDay();
        }

        return $due->endOfDay();
    }

    /** Days after the due day up to $end, holidays not counted. */
    public function lateDays(CarbonInterface $due, CarbonInterface $end): int
    {
        $day = $due->copy()->startOfDay()->addDay();
        if ($day->gt($end)) {
            return 0;
        }

        $holidays = $this->holidays();
        $count = 0;
        for ($i = 0; $day->lte($end) && $i < 3650; $i++, $day = $day->addDay()) {
            if (! $this->isClosed($day, $holidays)) {
                $count++;
            }
        }

        return $count;
    }

    /** Fridays and the holidays list (filled in step 3). */
    private function isClosed(CarbonInterface $day, array $holidays): bool
    {
        return $day->isFriday() || in_array($day->toDateString(), $holidays, true);
    }

    /** @return array<int, string> Y-m-d */
    protected function holidays(): array
    {
        return [];
    }
}
