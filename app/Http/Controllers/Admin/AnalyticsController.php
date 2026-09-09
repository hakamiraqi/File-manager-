<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    private const TRACKED_ACTIONS = [
        'file.uploaded' => 'رفع ملفات',
        'file.downloaded' => 'تحميل ملفات',
        'file.bulk_download' => 'تحميل أرشيفات',
        'share.created' => 'روابط مشاركة',
        'share.downloaded' => 'تحميلات المشاركة العامة',
        'folder.created' => 'مجلدات جديدة',
    ];

    public function index(Request $request): Response
    {
        $days = max(7, min(90, (int) $request->integer('days', 30)));
        $since = Carbon::now()->subDays($days - 1)->startOfDay();

        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'sqlite' ? "strftime('%Y-%m-%d', created_at)" : 'DATE(created_at)';

        $rows = ActivityLog::query()
            ->selectRaw("{$dateExpr} as day, action, COUNT(*) as total")
            ->whereIn('action', array_keys(self::TRACKED_ACTIONS))
            ->where('created_at', '>=', $since)
            ->groupBy('day', 'action')
            ->orderBy('day')
            ->get();

        $labels = [];
        for ($i = 0; $i < $days; $i++) {
            $labels[] = $since->copy()->addDays($i)->toDateString();
        }

        $series = [];
        foreach (self::TRACKED_ACTIONS as $action => $label) {
            $byDay = $rows->where('action', $action)->pluck('total', 'day');
            $series[] = [
                'label' => $label,
                'action' => $action,
                'data' => array_map(fn ($day) => (int) ($byDay[$day] ?? 0), $labels),
            ];
        }

        $totals = ActivityLog::query()
            ->selectRaw('action, COUNT(*) as total')
            ->whereIn('action', array_keys(self::TRACKED_ACTIONS))
            ->groupBy('action')
            ->pluck('total', 'action');

        return Inertia::render('Admin/Analytics', [
            'days' => $days,
            'labels' => $labels,
            'series' => $series,
            'totals' => collect(self::TRACKED_ACTIONS)->map(fn ($label, $action) => [
                'label' => $label,
                'total' => (int) ($totals[$action] ?? 0),
            ])->values(),
        ]);
    }
}
