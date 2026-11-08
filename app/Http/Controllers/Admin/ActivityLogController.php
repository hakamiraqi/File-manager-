<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Analytics\StaffAnalytics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * سجل النشاطات (redesigned 2026-11-05, by request): a timeline with filters
 * for period, employee, role, kind of activity, single action and text.
 */
class ActivityLogController extends Controller
{
    /** Arabic name of every action the app records. */
    public const LABELS = [
        'exam.created' => 'إنشاء امتحان',
        'exam.updated' => 'تعديل امتحان',
        'exam.deleted' => 'حذف امتحان',
        'exam.imported' => 'استيراد أسئلة',
        'exam.started' => 'بدء امتحان',
        'exam.submitted' => 'تسليم امتحان',
        'exam.graded' => 'تصحيح إجابة',
        'exam.students_imported' => 'استيراد طلاب',
        'admin.user_created' => 'إضافة مستخدم',
        'admin.user_updated' => 'تعديل مستخدم',
        'admin.user_deleted' => 'حذف مستخدم',
        'admin.settings_updated' => 'تعديل الإعدادات',
        'admin.permissions_updated' => 'تعديل الصلاحيات',
        'admin.security_updated' => 'تعديل الأمان',
        'admin.mail_settings_updated' => 'تعديل إعدادات البريد',
        'admin.translation_updated' => 'تعديل الترجمات',
        'admin.ip_rule_added' => 'إضافة قاعدة IP',
        'admin.ip_rule_removed' => 'حذف قاعدة IP',
    ];

    /** Kinds of activity for the filter chips; the exam groups plus admin. */
    public static function kinds(): array
    {
        return StaffAnalytics::CATEGORIES + [
            'admin' => array_values(array_filter(array_keys(self::LABELS), fn ($a) => str_starts_with($a, 'admin.'))),
        ];
    }

    public function index(Request $request, StaffAnalytics $analytics): Response
    {
        $filters = $this->filters($request);
        $period = $this->period($filters, $analytics);

        $logs = $this->query($filters, $period, true)
            ->with(['user:id,name,email,role,avatar_path', 'subject'])
            ->latest('created_at')->latest('id')
            ->paginate(40)
            ->withQueryString();

        $logs->getCollection()->transform(fn (ActivityLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'label' => self::LABELS[$log->action] ?? $log->action,
            'kind' => $this->kindOf($log->action),
            'description' => $log->description,
            'created_at' => $log->created_at?->toIso8601String(),
            'ip' => $log->ip_address,
            'agent' => $log->user_agent,
            'user' => $log->user ? [
                'id' => $log->user->id, 'name' => $log->user->name, 'email' => $log->user->email,
                'role' => $log->user->role, 'avatar_url' => $log->user->avatar_url,
            ] : null,
            'subject' => $log->subject ? [
                'type' => $log->subject_type,
                'id' => $log->subject->getKey(),
                'name' => $log->subject instanceof \App\Models\Exams\Exam ? $log->subject->text('title') : ($log->subject->name ?? null),
            ] : null,
        ]);

        // Counts per kind for the chips (every filter except the kind itself).
        $kindCounts = [];
        $base = $this->query(array_merge($filters, ['kind' => null, 'action' => null]), $period, true);
        $byAction = (clone $base)->selectRaw('action, COUNT(*) as c')->groupBy('action')->pluck('c', 'action');
        foreach (self::kinds() as $kind => $actions) {
            $kindCounts[$kind] = (int) $byAction->only($actions)->sum();
        }
        $kindCounts['all'] = (int) $byAction->sum();
        $kindCounts['other'] = $kindCounts['all'] - (int) $byAction->only(array_merge(...array_values(self::kinds())))->sum();

        return Inertia::render('Admin/ActivityLog', [
            'logs' => $logs,
            'filters' => $filters,
            'period' => $period ? ['from' => $period['from']->toDateString(), 'to' => $period['to']->subSecond()->toDateString()] : null,
            'kindCounts' => $kindCounts,
            'labels' => self::LABELS,
            'kinds' => [...array_keys(self::kinds()), 'other'],
            'users' => User::query()->orderBy('name')->limit(1000)->get(['id', 'name', 'role']),
            'roles' => User::query()->distinct()->orderBy('role')->pluck('role'),
            'shiftStart' => $analytics->shiftStartTime(),
        ]);
    }

    /** The filtered log as CSV (opens in Excel). */
    public function export(Request $request, StaffAnalytics $analytics): StreamedResponse
    {
        $filters = $this->filters($request);
        $period = $this->period($filters, $analytics);
        $rows = $this->query($filters, $period, true)->with('user:id,name,role')->latest('created_at')->limit(20000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['التاريخ والوقت (بغداد)', 'المستخدم', 'الدور', 'النشاط', 'التفاصيل', 'العنوان IP']);
            foreach ($rows as $log) {
                fputcsv($out, [
                    $log->created_at?->timezone(StaffAnalytics::TZ)->format('Y-m-d H:i'),
                    $log->user?->name ?? 'النظام / زائر',
                    $log->user?->role,
                    self::LABELS[$log->action] ?? $log->action,
                    $log->description,
                    $log->ip_address,
                ]);
            }
            fclose($out);
        }, 'activity-'.now(StaffAnalytics::TZ)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'preset' => ['nullable', 'string', 'in:all,'.implode(',', StaffAnalytics::PRESETS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user' => ['nullable', 'integer'],
            'role' => ['nullable', 'string', 'max:40'],
            'kind' => ['nullable', 'string', 'in:other,'.implode(',', array_keys(self::kinds()))],
            'action' => ['nullable', 'string', 'max:60'],
            'q' => ['nullable', 'string', 'max:100'],
            // Older links used user_id.
            'user_id' => ['nullable', 'integer'],
        ]);

        return [
            'preset' => $data['preset'] ?? 'all',
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'user' => $data['user'] ?? $data['user_id'] ?? null,
            'role' => $data['role'] ?? null,
            'kind' => $data['kind'] ?? null,
            'action' => $data['action'] ?? null,
            'q' => trim((string) ($data['q'] ?? '')) ?: null,
        ];
    }

    private function period(array $filters, StaffAnalytics $analytics): ?array
    {
        return $filters['preset'] === 'all' ? null : $analytics->period($filters['preset'], $filters['from'], $filters['to']);
    }

    private function query(array $filters, ?array $period, bool $withPeriod): Builder
    {
        $kinds = self::kinds();

        return ActivityLog::query()
            ->when($withPeriod && $period, fn ($q) => $q->where('created_at', '>=', $period['from']->utc())->where('created_at', '<', $period['to']->utc()))
            ->when($filters['user'], fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['role'], fn ($q, $role) => $q->whereIn('user_id', User::where('role', $role)->select('id')))
            ->when($filters['kind'], function ($q, $kind) use ($kinds) {
                $kind === 'other'
                    ? $q->whereNotIn('action', array_merge(...array_values($kinds)))
                    : $q->whereIn('action', $kinds[$kind]);
            })
            ->when($filters['action'], fn ($q, $a) => $q->where('action', $a))
            ->when($filters['q'], fn ($q, $text) => $q->where(fn ($w) => $w
                ->where('description', 'like', "%{$text}%")
                ->orWhere('ip_address', 'like', "%{$text}%")
                ->orWhereIn('user_id', User::where('name', 'like', "%{$text}%")->select('id'))));
    }

    private function kindOf(string $action): string
    {
        foreach (self::kinds() as $kind => $actions) {
            if (in_array($action, $actions, true)) {
                return $kind;
            }
        }

        return 'other';
    }
}
