<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Exams\Mirror;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** The standby server: the main one hands out its changes; the standby takes them every minute. */
class MirrorController extends Controller
{
    /** On the main server: what changed, for a standby that knows the key. */
    public function changes(Request $request, Mirror $mirror): JsonResponse
    {
        $key = Setting::get('mirror_key') ? Crypt::decryptString((string) Setting::get('mirror_key')) : null;
        abort_unless($key && hash_equals($key, (string) $request->header('X-Mirror-Key')), 403);
        $now = now();

        return response()->json(['now' => $now->toIso8601String(),
            'tables' => $mirror->changes($request->filled('since') ? Carbon::parse($request->input('since')) : null)]);
    }

    public function show(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        return Inertia::render('Exams/Monitor/Mirror', [
            'main' => ['enabled' => filled(Setting::get('mirror_key')), 'key' => session('mirror_key_shown')],
            'standby' => ['from' => Setting::get('mirror_from'), 'has_key' => filled(Setting::get('mirror_from_key')),
                'last_ok' => Setting::get('mirror_last_ok'), 'last_error' => Setting::get('mirror_last_error') ?: null, 'rows' => Setting::get('mirror_last_rows')],
            'address' => rtrim(config('app.url'), '/'),
        ]);
    }

    /** The main server's key: made new (shown once), or switched off. */
    public function key(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($request->boolean('off')) {
            Setting::set('mirror_key', '');

            return back()->with('success', 'أُوقف تسليم البيانات للسيرفر الاحتياطي.');
        }
        $key = Str::random(40);
        Setting::set('mirror_key', Crypt::encryptString($key));
        ActivityLog::record('exam.mirror', 'أنشأ مفتاحاً جديداً للسيرفر الاحتياطي');

        return back()->with('success', 'انسخ المفتاح الآن إلى السيرفر الاحتياطي؛ لا يظهر مرة أخرى.')->with('mirror_key_shown', $key);
    }

    /** On the standby: where the main server is, and its key. */
    public function standby(Request $request, Mirror $mirror): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['from' => ['nullable', 'url', 'max:300'], 'key' => ['nullable', 'string', 'max:100'], 'now' => ['sometimes', 'boolean']]);
        if ($request->boolean('now')) {
            $r = $mirror->pull();

            return back()->with(isset($r['error']) ? 'error' : 'success', isset($r['error']) ? 'تعذّر السحب: '.$r['error'] : 'سُحب '.($r['rows'] ?? 0).' صفاً.');
        }
        Setting::setMany(['mirror_from' => $data['from'] ?? '', 'mirror_last_at' => '']);
        if (filled($data['key'] ?? null)) {
            Setting::set('mirror_from_key', Crypt::encryptString($data['key']));
        }
        if (blank($data['from'] ?? null)) {
            Setting::set('mirror_from_key', '');
        }

        return back()->with('success', 'حُفظت إعدادات السيرفر الاحتياطي.');
    }
}
