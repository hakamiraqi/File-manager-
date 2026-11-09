<?php

namespace App\Services\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Computer;
use App\Models\Exams\MapItem;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * «خريطة القاعات» (2026-11-09, by request): the exam halls drawn the way FileNest draws its
 * library (same plan engine: halls, corridors, doors, stairs, floors, 2D/3D, Waze-style walking
 * directions), with every computer coloured by what is happening on it now: off, on, a student
 * working, help asked, locked, cut off, the wrong student, a wrong clock, finished.
 */
class HallMap
{
    public const SETTING = 'exam_hall_map';

    public const PLANS = 'hall-plans';

    /** @return array{width: float, depth: float, entrance: array{x: float, y: float}, name: string, storeys: list<string>, separate_halls: bool, backgrounds: array} */
    public function floor(): array
    {
        $saved = json_decode((string) Setting::get(self::SETTING, ''), true) ?: [];
        $width = (float) ($saved['width'] ?? 20);
        $depth = (float) ($saved['depth'] ?? 14);

        return [
            'width' => $width,
            'depth' => $depth,
            'entrance' => ['x' => (float) ($saved['entrance']['x'] ?? $width / 2), 'y' => (float) ($saved['entrance']['y'] ?? $depth)],
            'name' => (string) ($saved['name'] ?? ''),
            'storeys' => array_values(array_map('strval', $saved['storeys'] ?? [])) ?: ['الطابق الأرضي'],
            'separate_halls' => (bool) ($saved['separate_halls'] ?? false),
            'backgrounds' => array_values(array_map(fn ($b) => $b + ['url' => Storage::disk('public')->url($b['path'])], $saved['backgrounds'] ?? [])),
        ];
    }

    public function saveFloor(array $floor): void
    {
        $backgrounds = [];
        foreach ($floor['backgrounds'] ?? [] as $b) {
            $path = (string) ($b['path'] ?? '');
            if (! str_starts_with($path, self::PLANS.'/') || str_contains($path, '..')) {
                continue;
            }
            $backgrounds[] = [
                'storey' => (int) $b['storey'], 'path' => $path,
                'x' => round((float) $b['x'], 2), 'y' => round((float) $b['y'], 2),
                'width' => round((float) $b['width'], 3), 'aspect' => round((float) $b['aspect'], 5),
                'opacity' => round((float) ($b['opacity'] ?? 0.5), 2), 'hidden' => (bool) ($b['hidden'] ?? false),
            ];
        }
        Setting::set(self::SETTING, json_encode([
            'width' => (float) $floor['width'], 'depth' => (float) $floor['depth'],
            'entrance' => ['x' => (float) $floor['entrance']['x'], 'y' => (float) $floor['entrance']['y']],
            'name' => (string) ($floor['name'] ?? ''),
            'storeys' => array_values(array_map(fn ($n) => trim((string) $n), $floor['storeys'] ?? [])) ?: ['الطابق الأرضي'],
            'separate_halls' => (bool) ($floor['separate_halls'] ?? false),
            'backgrounds' => $backgrounds,
        ], JSON_UNESCAPED_UNICODE));
        $keep = array_column($backgrounds, 'path');
        foreach (Storage::disk('public')->files(self::PLANS) as $file) {
            if (! in_array($file, $keep, true)) {
                Storage::disk('public')->delete($file);
            }
        }
    }

    /** Everything on the plan, as the 2D/3D map draws it. */
    public function items(): Collection
    {
        $pcs = Computer::all()->keyBy('id');

        return MapItem::orderBy('code')->get()->map(fn (MapItem $s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'kind' => $s->kind, 'storey' => $s->storey, 'section_id' => null,
            'computer_id' => $s->computer_id, 'computer' => ($pc = $pcs[$s->computer_id] ?? null) ? ['id' => $pc->id, 'label' => $pc->label, 'room' => $pc->room, 'ip' => $pc->ip] : null,
            'x' => $s->x, 'y' => $s->y, 'rotation' => $s->rotation, 'width' => $s->width, 'depth' => $s->depth,
            'levels' => $s->levels, 'double_sided' => $s->double_sided, 'color' => $s->color,
            'points' => $s->points, 'height' => $s->height, 'locked' => (bool) $s->locked, 'group_key' => $s->group_key,
        ]);
    }

    /**
     * What every computer is doing now (optionally for one exam): its state, who sits at it,
     * the attempt there, and the problems to look at. Keyed by computer id.
     *
     * @return array{computers: array<int, array>, counts: array<string, int>, now: string}
     */
    /** How long a report from the browser keeps a computer marked «اشتباه». */
    public const SUSPECT_MINUTES = 10;

    public function live(?int $examId = null): array
    {
        $pcs = Computer::all();
        $byIp = $pcs->keyBy('ip');
        $quiet = now()->subSeconds(Computer::QUIET_SECONDS);

        // Attempts going on (or just finished) on any hall computer.
        $attempts = Attempt::with(['user:id,name,student_number,photo_path', 'exam:id,title,duration_minutes'])
            ->withCount(['answers as answered' => fn ($q) => $q->whereNotNull('response')])
            ->whereIn('ip', $byIp->keys())
            ->when($examId, fn ($q) => $q->where('exam_id', $examId))
            ->where(fn ($q) => $q->whereNull('submitted_at')->where('started_at', '>=', now()->subHours(12))
                ->orWhere('submitted_at', '>=', now()->subMinutes(45)))
            ->orderBy('started_at')->get()
            ->groupBy('ip')->map(fn ($list) => $list->sortBy(fn (Attempt $a) => [$a->submitted_at ? 1 : 0, -($a->last_seen_at ?? $a->started_at)->getTimestamp()])->first());

        // «اشتباه غش» (2026-11-09, by request): what the browser reported in the last minutes on
        // an attempt going on (the tab hidden, the window left, a copy or paste, full screen left…).
        $live = $attempts->filter(fn (Attempt $a) => ! $a->submitted_at)->pluck('id');
        $suspicion = $live->isEmpty() ? collect() : \App\Models\Exams\Event::whereIn('exam_attempt_id', $live)
            ->where('created_at', '>=', now()->subMinutes(self::SUSPECT_MINUTES))->where('type', '!=', 'offline_file')
            ->orderBy('created_at')->get(['exam_attempt_id', 'type', 'created_at'])->groupBy('exam_attempt_id');

        // Who should sit where: the computers students are bound to, for exams going on now.
        $activeExams = $examId ? collect([$examId]) : Attempt::whereNull('submitted_at')->where('started_at', '>=', now()->subHours(12))->distinct()->pluck('exam_id');
        $bound = DB::table('exam_attendance')->whereIn('exam_id', $activeExams)->whereNotNull('computer_id')
            ->join('users', 'users.id', '=', 'exam_attendance.user_id')
            ->get(['exam_attendance.exam_id', 'exam_attendance.computer_id', 'exam_attendance.user_id', 'users.name', 'users.student_number']);
        $boundTo = $bound->keyBy('computer_id');
        $seatOf = $bound->groupBy('exam_id')->map(fn ($rows) => $rows->keyBy('user_id'));

        $out = [];
        $counts = [];
        foreach ($pcs as $pc) {
            $suspect = null;
            $a = $attempts[$pc->ip] ?? null;
            $on = $pc->last_seen_at && $pc->last_seen_at->gte($quiet);
            $seen = collect([$pc->last_seen_at, $a?->last_seen_at])->filter()->max();
            $clock = $a && ! $a->submitted_at && $a->clock_offset !== null ? $a->clock_offset : ($on ? $pc->last_clock_offset : null);
            $expected = $boundTo[$pc->id] ?? null;
            $problems = [];
            if ($a && ! $a->submitted_at) {
                $mySeat = $seatOf[$a->exam_id][$a->user_id] ?? null;
                $wrong = $mySeat && (int) $mySeat->computer_id !== $pc->id;
                $events = $suspicion[$a->id] ?? collect();
                $state = match (true) {
                    (bool) $a->help_requested_at => 'help',
                    (bool) $a->locked_at => 'locked',
                    $events->isNotEmpty() => 'suspect',
                    $wrong => 'wrong_pc',
                    ($a->last_seen_at ?? $a->started_at)->lt(now()->subSeconds(90)) => 'disconnected',
                    $a->isExpired(Attempt::GRACE_SECONDS) => 'time_up',
                    default => 'working',
                };
                if ($wrong) {
                    $problems[] = 'wrong_pc';
                }
                if ($events->isNotEmpty()) {
                    $problems[] = 'suspect';
                    $suspect = ['count' => $events->count(), 'kinds' => $events->pluck('type')->unique()->values()->all(),
                        'last' => $events->last()->created_at->toIso8601String()];
                }
            } elseif ($a) {
                $state = 'submitted';
            } elseif ($expected) {
                $state = $on ? 'waiting' : 'off_expected';
            } else {
                $state = $on ? 'on' : 'off';
            }
            if (in_array($state, ['help', 'locked', 'disconnected', 'time_up', 'off_expected'], true)) {
                $problems[] = $state;
            }
            if ($clock !== null && abs($clock) > 60) {
                $problems[] = 'clock';
            }
            // A student bound here who is sitting somewhere else.
            if ($expected && (! $a || $a->user_id !== $expected->user_id) && $state !== 'off_expected') {
                $elsewhere = $attempts->first(fn (Attempt $x) => $x->user_id === $expected->user_id && ! $x->submitted_at && $x->ip !== $pc->ip);
                if ($elsewhere) {
                    $problems[] = 'student_elsewhere';
                }
            }
            $problems = array_values(array_unique($problems));
            // The clock: within a few seconds of the server's, a little off, or wrong (over a minute).
            $sync = $clock === null ? null : (abs($clock) <= 5 ? 'ok' : (abs($clock) <= 60 ? 'drift' : 'wrong'));
            $counts[$state] = ($counts[$state] ?? 0) + 1;
            $out[$pc->id] = [
                'id' => $pc->id, 'label' => $pc->label, 'room' => $pc->room, 'ip' => $pc->ip,
                'state' => $state, 'problems' => $problems,
                'seen' => $seen?->toIso8601String(), 'clock' => $clock, 'sync' => $sync, 'agent' => $pc->last_agent,
                'linked' => $on || ($a && ! $a->submitted_at && ($a->last_seen_at ?? $a->started_at)->gte(now()->subSeconds(90))),
                'suspect' => $suspect ?? null,
                'student' => $a?->user ? ['id' => $a->user->id, 'name' => $a->user->name, 'number' => $a->user->student_number] : null,
                'expected' => $expected ? ['id' => (int) $expected->user_id, 'name' => $expected->name, 'number' => $expected->student_number] : null,
                'exam' => $a?->exam ? ['id' => $a->exam->id, 'title' => $a->exam->text('title')] : null,
                'attempt' => $a ? [
                    'id' => $a->id, 'answered' => (int) $a->answered, 'seconds_left' => $a->submitted_at ? null : $a->secondsLeft(),
                    'started_at' => $a->started_at?->toIso8601String(), 'submitted_at' => $a->submitted_at?->toIso8601String(),
                    'help' => $a->help_requested_at?->toIso8601String(), 'locked' => $a->locked_at ? ['reason' => $a->lock_reason, 'code' => $a->resume_code] : null,
                ] : null,
            ];
        }
        $counts['problems'] = count(array_filter($out, fn ($c) => $c['problems']));

        return ['computers' => $out, 'counts' => $counts, 'now' => now()->toIso8601String()];
    }

    /** A sign of life from a hall computer: any page of the site, every half minute. */
    public static function heard(string $ip, ?int $clientMs, ?string $agent, ?int $userId, ?int $offsetMs = null): ?Computer
    {
        $pc = Computer::where('ip', $ip)->first();
        if (! $pc) {
            return null;
        }
        $offset = $offsetMs !== null ? (int) round($offsetMs / 1000) : ($clientMs ? (int) round(($clientMs - now()->getTimestampMs()) / 1000) : null);
        $pc->forceFill([
            'last_seen_at' => now(),
            'last_clock_offset' => $offset !== null && abs($offset) < 86400 * 400 ? $offset : $pc->last_clock_offset,
            'last_agent' => $agent ? mb_substr($agent, 0, 200) : $pc->last_agent,
            'last_user_id' => $userId,
        ])->saveQuietly();

        return $pc;
    }
}
