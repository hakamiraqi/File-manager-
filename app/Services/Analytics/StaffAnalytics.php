<?php

namespace App\Services\Analytics;

use App\Models\ActivityLog;
use App\Models\Folder;
use App\Models\Library\Book;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff activity analytics for the admin "التحليلات" page: a period (preset or custom, in
 * Baghdad time), the activity of every employee in it grouped into categories, a comparison
 * with the previous period of the same length, and a data-quality score for the records each
 * employee entered (thesis records and library books).
 */
class StaffAnalytics
{
    public const TZ = 'Asia/Baghdad';

    /** Activity categories → the logged actions that count towards them. */
    public const CATEGORIES = [
        'uploads' => ['file.uploaded', 'file.remote_uploaded'],
        'data_entry' => [
            'folder.created', 'folder.updated', 'file.updated', 'thesis.imported',
            'library.book_created', 'library.book_updated', 'library.import',
            'video_question.created', 'video_question.updated', 'video_question.imported', 'subtitle.uploaded',
        ],
        'circulation' => [
            'library.loan', 'library.return', 'library.renew', 'library.hold',
            'library.fine_paid', 'library.fine_waived', 'library.rfid', 'library.inventory', 'library.shelving',
        ],
        'sharing' => ['share.created', 'share.emailed'],
        'downloads' => ['file.downloaded', 'file.bulk_download'],
        'deletions' => ['file.trashed', 'folder.trashed', 'library.book_deleted', 'trash.emptied'],
    ];

    public const PRESETS = [
        'today', 'shift', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month',
        'last_7', 'last_30', 'last_90', 'this_year', 'custom',
    ];

    /** Fields that make a thesis record complete (IQDR-style record). */
    public const THESIS_QUALITY_FIELDS = [
        'thesis_title', 'researcher_name', 'degree', 'supervisor_name', 'publication_year',
        'department', 'subject', 'keywords', 'abstract', 'defense_date', 'language', 'pages',
    ];

    public const BOOK_QUALITY_FIELDS = ['title', 'authors', 'isbn', 'publisher', 'year', 'language', 'section_id', 'description'];

    public const FILE_TYPES = [
        'pdf' => ['pdf'],
        'word' => ['doc', 'docx', 'odt', 'rtf', 'txt'],
        'excel' => ['xls', 'xlsx', 'ods', 'csv'],
        'slides' => ['ppt', 'pptx', 'odp'],
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'svg', 'heic'],
        'video' => ['mp4', 'mkv', 'mov', 'avi', 'webm', 'wmv', 'm4v'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
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

    /**
     * Logged actions per hour (UTC), per user and action, for a window. One grouped query
     * serves the trend, the per-employee table, the hour-of-day and weekday charts.
     */
    private function hourly(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        $driver = DB::connection()->getDriverName();
        $hourExpr = match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d %H', created_at)",
            'pgsql' => "to_char(created_at, 'YYYY-MM-DD HH24')",
            default => "DATE_FORMAT(created_at, '%Y-%m-%d %H')",
        };

        return ActivityLog::query()
            ->selectRaw("{$hourExpr} as hour, user_id, action, COUNT(*) as total")
            ->whereIn('action', array_merge(...array_values(self::CATEGORIES)))
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $to->utc())
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->whereIn('user_id', User::where('role', $role)->select('id')))
            ->groupBy('hour', 'user_id', 'action')
            ->get()
            ->map(function ($row) {
                $at = CarbonImmutable::createFromFormat('Y-m-d H', $row->hour, 'UTC')->setTimezone(self::TZ);

                return (object) [
                    'at' => $at,
                    'user_id' => (int) $row->user_id,
                    'category' => $this->categoryOf($row->action),
                    'total' => (int) $row->total,
                ];
            });
    }

    private function categoryOf(string $action): string
    {
        foreach (self::CATEGORIES as $category => $actions) {
            if (in_array($action, $actions, true)) {
                return $category;
            }
        }

        return 'other';
    }

    /** Uploaded files in a window: count and bytes per user and per type. */
    private function uploads(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        return DB::table('files')
            ->selectRaw('user_id, LOWER(extension) as ext, COUNT(*) as files, COALESCE(SUM(size), 0) as bytes')
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $to->utc())
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->whereIn('user_id', User::where('role', $role)->select('id')))
            ->groupBy('user_id', 'ext')
            ->get();
    }

    private function fileType(?string $ext): string
    {
        foreach (self::FILE_TYPES as $type => $exts) {
            if (in_array($ext, $exts, true)) {
                return $type;
            }
        }

        return 'other';
    }

    /**
     * Completeness of the thesis records and books each user created or edited in the
     * window, as a percentage of the filled quality fields.
     *
     * @return array<int, array{records:int, score:int, missing:array<string,int>}>
     */
    private function quality(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $logs = ActivityLog::query()
            ->select('user_id', 'subject_type', 'subject_id')
            ->whereIn('action', ['folder.created', 'folder.updated', 'thesis.imported', 'library.book_created', 'library.book_updated'])
            ->whereNotNull('user_id')
            ->whereNotNull('subject_id')
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $to->utc())
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->distinct()
            ->get();

        $folderType = (new Folder)->getMorphClass();
        $bookType = (new Book)->getMorphClass();

        // Theses created in the window count for their owner too (an import logs one line only).
        Folder::query()
            ->where('folder_type', 'thesis')
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $to->utc())
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->get(['id', 'user_id'])
            ->each(fn ($f) => $logs->push((object) ['user_id' => $f->user_id, 'subject_type' => $folderType, 'subject_id' => $f->id]));
        if ($filters['role'] ?? null) {
            $allowed = User::where('role', $filters['role'])->pluck('id')->all();
            $logs = $logs->filter(fn ($l) => in_array((int) $l->user_id, $allowed, true))->values();
        }

        $theses = Folder::query()
            ->whereIn('id', $logs->where('subject_type', $folderType)->pluck('subject_id')->unique())
            ->where('folder_type', 'thesis')
            ->get(array_merge(['id'], self::THESIS_QUALITY_FIELDS))
            ->keyBy('id');
        $books = Book::query()
            ->whereIn('id', $logs->where('subject_type', $bookType)->pluck('subject_id')->unique())
            ->get(array_merge(['id'], self::BOOK_QUALITY_FIELDS))
            ->keyBy('id');

        $out = [];
        $seen = [];
        foreach ($logs as $log) {
            $record = match ($log->subject_type) {
                $folderType => $theses[$log->subject_id] ?? null,
                $bookType => $books[$log->subject_id] ?? null,
                default => null,
            };
            $key = $log->user_id.'|'.$log->subject_type.'|'.$log->subject_id;
            if (! $record || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $fields = $log->subject_type === $folderType ? self::THESIS_QUALITY_FIELDS : self::BOOK_QUALITY_FIELDS;
            $prefix = $log->subject_type === $folderType ? 'thesis' : 'book';

            $filled = 0;
            $entry = $out[$log->user_id] ?? ['records' => 0, 'points' => 0.0, 'missing' => []];
            foreach ($fields as $field) {
                $value = $record->{$field};
                if ($value !== null && trim((string) ($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)) !== '') {
                    $filled++;
                } else {
                    $entry['missing']["{$prefix}.{$field}"] = ($entry['missing']["{$prefix}.{$field}"] ?? 0) + 1;
                }
            }
            $entry['records']++;
            $entry['points'] += $filled / count($fields);
            $out[$log->user_id] = $entry;
        }

        return collect($out)->map(fn ($e) => [
            'records' => $e['records'],
            'score' => (int) round($e['points'] / max(1, $e['records']) * 100),
            'missing' => $e['missing'],
        ])->all();
    }

    /** Everything the analytics page shows. */
    public function report(array $period, array $filters = []): array
    {
        $current = $this->hourly($period['from'], $period['to'], $filters);
        $previous = $this->hourly($period['prevFrom'], $period['prevTo'], $filters);
        $uploads = $this->uploads($period['from'], $period['to'], $filters);
        $prevUploads = $this->uploads($period['prevFrom'], $period['prevTo'], $filters);
        $quality = $this->quality($period['from'], $period['to'], $filters);

        $categories = array_keys(self::CATEGORIES);
        $sumCategory = fn (Collection $rows, string $c) => (int) $rows->where('category', $c)->sum('total');

        // Totals and the previous period.
        $kpis = [];
        foreach ($categories as $c) {
            $kpis[$c] = ['value' => $sumCategory($current, $c), 'previous' => $sumCategory($previous, $c)];
        }
        $kpis['bytes'] = ['value' => (int) $uploads->sum('bytes'), 'previous' => (int) $prevUploads->sum('bytes')];
        $kpis['active_staff'] = ['value' => $current->pluck('user_id')->unique()->count(), 'previous' => $previous->pluck('user_id')->unique()->count()];
        $kpis['total'] = ['value' => (int) $current->sum('total'), 'previous' => (int) $previous->sum('total')];

        // Trend buckets: hours for a day or two, otherwise days (weeks over ~4 months).
        $buckets = [];
        $span = $period['from']->diffInDays($period['to']);
        $unit = $period['hourly'] ? 'hour' : ($span > 120 ? 'week' : 'day');
        $format = ['hour' => 'Y-m-d H', 'day' => 'Y-m-d', 'week' => 'o-W'][$unit];
        for ($t = $period['from']; $t->lt($period['to']) && count($buckets) < 800; $t = $t->add(1, $unit)) {
            $buckets[$t->format($format)] = ['label' => $unit === 'hour' ? $t->format('m-d H:00') : $t->format('Y-m-d')];
        }
        $series = [];
        foreach ($categories as $c) {
            $byBucket = $current->where('category', $c)->groupBy(fn ($r) => $r->at->format($format))->map->sum('total');
            $series[$c] = array_map(fn ($k) => (int) ($byBucket[$k] ?? 0), array_keys($buckets));
        }

        // Hour of day and weekday (Saturday first).
        $hours = array_fill(0, 24, 0);
        $weekdays = array_fill(0, 7, 0);
        foreach ($current as $r) {
            $hours[(int) $r->at->format('G')] += $r->total;
            $weekdays[($r->at->dayOfWeek + 1) % 7] += $r->total;
        }

        // Employees.
        $userIds = $current->pluck('user_id')->merge($uploads->pluck('user_id'))->merge(array_keys($quality))->unique()->filter();
        $users = User::whereIn('id', $userIds)->get(['id', 'name', 'email', 'role', 'is_librarian'])->keyBy('id');
        $staff = [];
        foreach ($userIds as $id) {
            $mine = $current->where('user_id', $id);
            $row = [
                'id' => (int) $id,
                'name' => $users[$id]->name ?? '—',
                'email' => $users[$id]->email ?? '',
                'role' => $users[$id]->role ?? '',
                'librarian' => (bool) ($users[$id]->is_librarian ?? false),
                'total' => (int) $mine->sum('total'),
                'previous' => (int) $previous->where('user_id', $id)->sum('total'),
                'files' => (int) $uploads->where('user_id', $id)->sum('files'),
                'bytes' => (int) $uploads->where('user_id', $id)->sum('bytes'),
                'active_days' => $mine->map(fn ($r) => $r->at->toDateString())->unique()->count(),
                'last_at' => optional($mine->sortByDesc('at')->first())->at?->format('Y-m-d H:i'),
                'quality' => $quality[$id]['score'] ?? null,
                'records' => $quality[$id]['records'] ?? 0,
            ];
            foreach ($categories as $c) {
                $row[$c] = (int) $mine->where('category', $c)->sum('total');
            }
            $staff[] = $row;
        }
        usort($staff, fn ($a, $b) => [$b['total'], $b['files']] <=> [$a['total'], $a['files']]);
        $top = max(1, $staff[0]['total'] ?? 0);
        $all = max(1, array_sum(array_column($staff, 'total')));
        foreach ($staff as $i => &$row) {
            $row['rank'] = $i + 1;
            $row['index'] = (int) round($row['total'] / $top * 100);
            $row['share'] = round($row['total'] / $all * 100, 1);
        }
        unset($row);

        // File types.
        $types = [];
        foreach ($uploads as $u) {
            $type = $this->fileType($u->ext);
            $types[$type] ??= ['type' => $type, 'files' => 0, 'bytes' => 0];
            $types[$type]['files'] += (int) $u->files;
            $types[$type]['bytes'] += (int) $u->bytes;
        }
        usort($types, fn ($a, $b) => $b['files'] <=> $a['files']);

        // Quality overall: the fields most often left empty.
        $missing = [];
        $records = 0;
        $points = 0;
        foreach ($quality as $q) {
            $records += $q['records'];
            $points += $q['score'] * $q['records'];
            foreach ($q['missing'] as $field => $n) {
                $missing[$field] = ($missing[$field] ?? 0) + $n;
            }
        }
        arsort($missing);

        return [
            'kpis' => $kpis,
            'trend' => ['unit' => $unit, 'labels' => array_column($buckets, 'label'), 'series' => $series],
            'hours' => $hours,
            'weekdays' => $weekdays,
            'staff' => $staff,
            'fileTypes' => array_values($types),
            'quality' => [
                'records' => $records,
                'score' => $records ? (int) round($points / $records) : null,
                'missing' => collect($missing)->take(8)->map(fn ($n, $f) => ['field' => $f, 'count' => $n])->values(),
            ],
        ];
    }
}
