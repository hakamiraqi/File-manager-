<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\CoversRun;
use App\Http\Controllers\Controller;
use App\Jobs\RunCoversNow;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Setting;
use App\Services\Covers\CoverSettings;
use App\Services\Covers\CoverStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

/** «أغلفة الكتب» in the admin pages (2026-11-12): when and where covers are looked for. */
class CoverController extends Controller
{
    public function index(Request $request): Response
    {
        $kinds = CoverSettings::kinds();
        $counts = File::whereIn('extension', $kinds)->selectRaw('cover_status, count(*) as n')->groupBy('cover_status')->pluck('n', 'cover_status');
        $filter = in_array($request->query('show'), ['found', 'manual', 'none', 'small', 'removed', 'error'], true) ? $request->query('show') : 'covers';

        $files = File::query()->whereIn('extension', $kinds)
            ->when($filter === 'covers', fn ($q) => $q->whereNotNull('cover_source'), fn ($q) => $q->where('cover_status', $filter))
            ->when($request->query('q'), fn ($q, $s) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%'))
            ->with('folder:id,name')
            ->orderByDesc('cover_checked_at')
            ->paginate(36)->withQueryString()
            ->through(fn (File $f) => [
                'id' => $f->id, 'name' => $f->name, 'folder' => $f->folder?->name, 'folder_id' => $f->folder_id,
                'status' => $f->cover_status, 'source' => $f->cover_source, 'isbn' => $f->cover_isbn,
                'checked_at' => $f->cover_checked_at?->toIso8601String(), 'thumbnail_path' => $f->thumbnail_path,
            ]);

        $settings = CoverSettings::all();
        $settings['covers_days'] = CoverSettings::days();

        return Inertia::render('Admin/Covers', [
            'settings' => $settings,
            'googleKeySet' => filled(Setting::get('covers_google_key')),
            'counts' => [
                'waiting' => File::whereIn('extension', $kinds)->whereNull('cover_status')->count(),
                'found' => (int) ($counts['found'] ?? 0),
                'manual' => (int) ($counts['manual'] ?? 0),
                'none' => (int) ($counts['none'] ?? 0),
                'small' => (int) ($counts['small'] ?? 0),
                'removed' => (int) ($counts['removed'] ?? 0),
                'error' => (int) ($counts['error'] ?? 0),
                'due' => CoversRun::candidates()->count(),
            ],
            'books' => [
                'with' => \App\Models\Library\Book::whereNotNull('cover_path')->count(),
                'without' => \App\Models\Library\Book::whereNull('cover_path')->count(),
                'due' => CoversRun::bookCandidates()->count(),
            ],
            'state' => [
                'in_window' => CoverSettings::inWindow(),
                'next_start' => CoverSettings::nextStart()?->toIso8601String(),
                'last' => json_decode((string) Setting::get('covers_last_run'), true),
            ],
            'files' => $files,
            'filter' => $filter,
            'q' => (string) $request->query('q', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $time = ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $data = $request->validate([
            'covers_enabled' => ['boolean'],
            'covers_title_search' => ['boolean'],
            'covers_library' => ['boolean'],
            'covers_openlibrary' => ['boolean'],
            'covers_google' => ['boolean'],
            'covers_days' => ['array'], 'covers_days.*' => ['integer', 'between:0,6'],
            'covers_start' => $time,
            'covers_end' => $time,
            'covers_batch' => ['required', 'integer', 'between:1,500'],
            'covers_min_pages' => ['required', 'integer', 'between:0,5000'],
            'covers_retry_days' => ['required', 'integer', 'between:1,365'],
            'covers_kinds' => ['required', 'array', 'min:1'], 'covers_kinds.*' => ['in:pdf,epub,djvu,doc,docx'],
            'covers_google_key' => ['nullable', 'string', 'max:200'],
            'covers_google_clear' => ['nullable', 'boolean'],
        ]);
        foreach (['covers_enabled', 'covers_title_search', 'covers_library', 'covers_openlibrary', 'covers_google'] as $k) {
            $data[$k] = $request->boolean($k) ? '1' : '0';
        }
        $data['covers_days'] = implode(',', collect($data['covers_days'] ?? [])->unique()->sort()->all());
        $data['covers_kinds'] = implode(',', array_unique($data['covers_kinds']));
        if ($request->boolean('covers_google_clear')) {
            $data['covers_google_key'] = '';
        } elseif (filled($data['covers_google_key'] ?? null)) {
            $data['covers_google_key'] = Crypt::encryptString(trim($data['covers_google_key']));
        } else {
            unset($data['covers_google_key']);
        }
        unset($data['covers_google_clear']);

        Setting::setMany(collect($data)->map(fn ($v) => (string) $v)->all());
        ActivityLog::record('settings.covers', 'حدّث إعدادات أغلفة الكتب');

        return back()->with('success', 'تم حفظ إعدادات الأغلفة.');
    }

    public function runNow(): RedirectResponse
    {
        RunCoversNow::dispatch();

        return back()->with('success', 'بدأ البحث عن الأغلفة الآن؛ حدّث الصفحة بعد قليل.');
    }

    /** Look again at the books with no cover found (or skipped as short). */
    public function retry(Request $request): RedirectResponse
    {
        $which = $request->boolean('small') ? ['none', 'error', 'small'] : ['none', 'error'];
        $n = File::whereIn('cover_status', $which)->update(['cover_status' => null]);
        $n += \App\Models\Library\Book::whereNull('cover_path')->whereIn('cover_status', ['none', 'error'])->update(['cover_status' => null]);

        return back()->with('success', "أُعيد {$n} ملف إلى قائمة البحث.");
    }

    public function remove(File $file): RedirectResponse
    {
        CoverStore::remove($file);

        return back()->with('success', 'أُزيل الغلاف.');
    }
}
