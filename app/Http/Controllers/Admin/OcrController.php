<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunOcrNow;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\OcrLoadSample;
use App\Models\Setting;
use App\Services\Ocr\LoadMonitor;
use App\Services\Ocr\OcrEngine;
use App\Services\Ocr\OcrRunner;
use App\Services\Ocr\OcrSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** «التعرف على النصوص»: the OCR queue, its hours and load limits, and how the computer coped. */
class OcrController extends Controller
{
    public const STATUSES = ['pending', 'processing', 'done', 'failed', 'skipped'];

    public function index(Request $request, OcrEngine $engine, OcrRunner $runner, LoadMonitor $monitor): Response
    {
        $status = in_array($request->query('status'), [...self::STATUSES, 'all'], true) ? $request->query('status') : 'pending';
        $q = trim((string) $request->query('q', ''));

        $files = File::query()->whereNotNull('ocr_status')
            ->when($status !== 'all', fn ($w) => $w->where('ocr_status', $status))
            ->when($q !== '', fn ($w) => $w->where('name', 'like', "%{$q}%"))
            ->with('folder:id,name')
            ->when(in_array($status, ['pending', 'processing'], true),
                fn ($w) => $w->orderByDesc('ocr_priority')->orderBy('id'),
                fn ($w) => $w->latest('updated_at'))
            ->paginate(25)->withQueryString()
            ->through(fn (File $f) => [
                'id' => $f->id, 'name' => $f->name, 'extension' => $f->extension, 'size' => $f->human_size,
                'folder' => $f->folder?->name, 'folder_id' => $f->folder_id,
                'status' => $f->ocr_status, 'priority' => $f->ocr_priority, 'attempts' => $f->ocr_attempts,
                'error' => $f->ocr_error, 'seconds' => $f->ocr_seconds, 'pages' => $f->ocr_pages,
                'done_at' => $f->ocr_done_at?->toIso8601String(),
                'chars' => $f->ocr_status === 'done' ? mb_strlen((string) $f->content_text) : null,
            ]);

        $counts = File::whereNotNull('ocr_status')->selectRaw('ocr_status, COUNT(*) as c')->groupBy('ocr_status')->pluck('c', 'ocr_status');
        $latest = OcrLoadSample::latest('id')->first();
        $now = $monitor->sample();
        $next = OcrSettings::nextStart();

        // Load over the last 24 hours in 10-minute steps.
        $samples = OcrLoadSample::where('created_at', '>=', now()->subDay())->orderBy('id')->get();
        $chart = $samples->groupBy(fn ($s) => $s->created_at->copy()->setTimezone(OcrSettings::TIMEZONE)->format('Y-m-d H:').str_pad((string) (intdiv($s->created_at->copy()->setTimezone(OcrSettings::TIMEZONE)->minute, 10) * 10), 2, '0', STR_PAD_LEFT))
            ->map(fn ($g, $k) => [
                't' => substr($k, 11),
                'cpu' => $g->whereNotNull('cpu')->avg('cpu') === null ? null : round($g->whereNotNull('cpu')->avg('cpu'), 1),
                'ram' => $g->whereNotNull('ram_free_mb')->avg('ram_free_mb') === null ? null : (int) $g->whereNotNull('ram_free_mb')->avg('ram_free_mb'),
                'users' => (int) $g->max('active_users'),
                'processed' => (int) $g->sum('processed'),
                'running' => $g->contains(fn ($s) => $s->processed > 0),
            ])->values();

        $week = collect(range(6, 0))->map(function ($d) {
            $day = now(OcrSettings::TIMEZONE)->subDays($d)->startOfDay();

            return [
                'day' => $day->toDateString(),
                'done' => File::where('ocr_status', 'done')->whereBetween('ocr_done_at', [$day->copy()->utc(), $day->copy()->endOfDay()->utc()])->count(),
            ];
        });
        $speed = File::where('ocr_status', 'done')->where('ocr_pages', '>', 0)->where('ocr_done_at', '>=', now()->subDays(7))
            ->selectRaw('SUM(ocr_seconds) as s, SUM(ocr_pages) as p')->first();

        return Inertia::render('Admin/Ocr', [
            'settings' => OcrSettings::all(),
            'engine' => $engine->info(),
            'counts' => [
                ...collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
                'new' => $runner->candidates()->count(),
            ],
            'state' => [
                'in_window' => OcrSettings::inWindow(),
                'next_start' => $next?->toIso8601String(),
                'last' => $latest ? [
                    'state' => $latest->state, 'reason' => $latest->reason, 'processed' => $latest->processed,
                    'at' => $latest->created_at?->toIso8601String(),
                ] : null,
                'load' => $now,
                'busy_reason' => $monitor->busyReason($now),
                'seconds_per_page' => $speed && $speed->p ? round($speed->s / $speed->p, 1) : null,
            ],
            'chart' => $chart,
            'week' => $week,
            'files' => $files,
            'filters' => ['status' => $status, 'q' => $q],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $time = ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $data = $request->validate([
            'ocr_enabled' => ['boolean'],
            'ocr_report' => ['boolean'],
            'ocr_days' => ['array'], 'ocr_days.*' => ['integer', 'between:0,6'],
            'ocr_all_day_days' => ['array'], 'ocr_all_day_days.*' => ['integer', 'between:0,6'],
            'ocr_start' => $time,
            'ocr_end' => $time,
            'ocr_batch' => ['required', 'integer', 'between:1,500'],
            'ocr_languages' => ['required', Rule::in(['ara+eng', 'ara', 'eng'])],
            'ocr_quality' => ['nullable', Rule::in(['best', 'fast'])],
            'ocr_max_cpu' => ['required', 'integer', 'between:0,100'],
            'ocr_min_ram_mb' => ['required', 'integer', 'between:0,65536'],
            'ocr_min_disk_gb' => ['required', 'integer', 'between:0,10000'],
            'ocr_max_users' => ['required', 'integer', 'between:0,10000'],
            'ocr_timeout_minutes' => ['required', 'integer', 'between:1,120'],
            'ocr_max_pages' => ['required', 'integer', 'between:1,5000'],
            'ocr_max_mb' => ['required', 'integer', 'between:1,5000'],
        ]);
        $data['ocr_enabled'] = $request->boolean('ocr_enabled') ? '1' : '0';
        $data['ocr_report'] = $request->boolean('ocr_report') ? '1' : '0';
        $data['ocr_days'] = implode(',', collect($data['ocr_days'] ?? [])->unique()->sort()->all());
        $data['ocr_all_day_days'] = implode(',', collect($data['ocr_all_day_days'] ?? [])->unique()->sort()->all());

        Setting::setMany(collect($data)->reject(fn ($v) => $v === null)->map(fn ($v) => (string) $v)->all());
        ActivityLog::record('settings.ocr', 'حدّث إعدادات التعرف على النصوص');

        return back()->with('success', 'تم حفظ إعدادات التعرف على النصوص.');
    }

    /** Queue every file that needs OCR now (otherwise each round adds up to 1000). */
    public function queue(OcrRunner $runner): RedirectResponse
    {
        $n = $runner->enqueue(null);

        return back()->with('success', $n ? "أُضيف {$n} ملف إلى قائمة الانتظار." : 'لا توجد ملفات جديدة تحتاج تعرفاً.');
    }

    public function runNow(): RedirectResponse
    {
        RunOcrNow::dispatch();

        return back()->with('success', 'بدأت دفعة قصيرة (5 ملفات) الآن، وتتوقف إذا انشغل الحاسوب.');
    }

    public function retryFailed(): RedirectResponse
    {
        $n = File::where('ocr_status', 'failed')->update(['ocr_status' => 'pending', 'ocr_attempts' => 0, 'ocr_error' => null, 'ocr_priority' => 0]);

        return back()->with('success', "أُعيد {$n} ملف إلى قائمة الانتظار.");
    }

    public function file(Request $request, File $file, string $action): RedirectResponse
    {
        $changes = match ($action) {
            'retry' => ['ocr_status' => 'pending', 'ocr_attempts' => 0, 'ocr_error' => null],
            'skip' => ['ocr_status' => 'skipped'],
            'prioritize' => ['ocr_status' => 'pending', 'ocr_priority' => max(100, (int) File::max('ocr_priority') + 1)],
            default => abort(404),
        };
        $file->forceFill($changes)->save();

        return back()->with('success', match ($action) {
            'retry' => 'أُعيد الملف إلى قائمة الانتظار.',
            'skip' => 'لن يُعالج هذا الملف.',
            default => 'سيُعالج هذا الملف أولاً.',
        });
    }
}
