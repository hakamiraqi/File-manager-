<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Analytics\StaffAnalytics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function index(Request $request, StaffAnalytics $analytics): Response
    {
        [$period, $filters] = $this->resolve($request, $analytics);

        return Inertia::render('Admin/Analytics', [
            'filters' => $filters + [
                'preset' => $period['preset'],
                'from' => $period['from']->toDateString(),
                'to' => $period['to']->subSecond()->toDateString(),
            ],
            'period' => [
                'from' => $period['from']->format('Y-m-d H:i'),
                'to' => $period['to']->format('Y-m-d H:i'),
                'prevFrom' => $period['prevFrom']->format('Y-m-d H:i'),
                'prevTo' => $period['prevTo']->format('Y-m-d H:i'),
            ],
            'shiftStart' => $analytics->shiftStartTime(),
            'report' => $analytics->report($period, $filters),
            'users' => User::query()->orderBy('name')->limit(1000)->get(['id', 'name', 'role']),
            'roles' => User::query()->distinct()->orderBy('role')->pluck('role'),
        ]);
    }

    /** The employee comparison as CSV (opens in Excel). */
    public function export(Request $request, StaffAnalytics $analytics): StreamedResponse
    {
        [$period, $filters] = $this->resolve($request, $analytics);
        $report = $analytics->report($period, $filters);
        $name = 'staff-performance-'.$period['from']->format('Ymd').'-'.$period['to']->subSecond()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($report, $period) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['الفترة', $period['from']->format('Y-m-d H:i').' → '.$period['to']->format('Y-m-d H:i').' (بتوقيت بغداد)']);
            fputcsv($out, []);
            fputcsv($out, [
                'الترتيب', 'الموظف', 'البريد', 'الدور', 'الملفات المرفوعة', 'حجم المرفوع (MB)', 'عمليات الرفع',
                'إدخال البيانات', 'الإعارة', 'المشاركة', 'التحميل', 'الحذف', 'مجموع النشاط', 'الفترة السابقة',
                'نسبة المساهمة %', 'مؤشر الأداء', 'جودة البيانات %', 'السجلات المُدخلة', 'أيام النشاط', 'آخر نشاط',
            ]);
            foreach ($report['staff'] as $s) {
                fputcsv($out, [
                    $s['rank'], $s['name'], $s['email'], $s['role'], $s['files'], round($s['bytes'] / 1048576, 1), $s['uploads'],
                    $s['data_entry'], $s['circulation'], $s['sharing'], $s['downloads'], $s['deletions'], $s['total'], $s['previous'],
                    $s['share'], $s['index'], $s['quality'] ?? '', $s['records'], $s['active_days'], $s['last_at'],
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: array, 1: array{user: ?int, role: ?string}} */
    private function resolve(Request $request, StaffAnalytics $analytics): array
    {
        $data = $request->validate([
            'preset' => ['nullable', 'string', 'in:'.implode(',', StaffAnalytics::PRESETS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user' => ['nullable', 'integer'],
            'role' => ['nullable', 'string', 'max:40'],
        ]);

        return [
            $analytics->period($data['preset'] ?? 'last_30', $data['from'] ?? null, $data['to'] ?? null),
            ['user' => isset($data['user']) ? (int) $data['user'] : null, 'role' => $data['role'] ?? null],
        ];
    }
}
