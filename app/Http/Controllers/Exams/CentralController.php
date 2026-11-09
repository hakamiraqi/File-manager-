<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Exam;
use App\Models\Setting;
use App\Services\Exams\CentralSync;
use App\Services\Exams\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/** The central server's side of hall sync, and the page where both sides are set up. */
class CentralController extends Controller
{
    public function __construct(private Transfer $transfer) {}

    private function hall(Request $request): void
    {
        $key = CentralSync::hallKey();
        abort_unless(! config('offline.edition') && $key && hash_equals($key, (string) $request->header('X-Hall-Key')), 403);
    }

    /** The exams handed to the halls. */
    public function exams(Request $request): JsonResponse
    {
        $this->hall($request);

        return response()->json(['exams' => Exam::where('hall_sync', true)->whereNotNull('transfer_key')->get(['transfer_key', 'title', 'updated_at'])
            ->map(fn (Exam $e) => ['transfer_key' => $e->transfer_key, 'title' => $e->text('title'), 'updated_at' => $e->updated_at?->toIso8601String()])]);
    }

    public function exam(Request $request, string $key): JsonResponse
    {
        $this->hall($request);
        $exam = Exam::where('hall_sync', true)->where('transfer_key', $key)->firstOrFail();

        return response()->json($this->transfer->exportExam($exam));
    }

    /** A hall's results, into the same exam here. */
    public function results(Request $request, string $key): JsonResponse
    {
        $this->hall($request);
        $exam = Exam::where('transfer_key', $key)->firstOrFail();
        try {
            $done = $this->transfer->importResults($exam, $request->all());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        ActivityLog::record('exam.central_results', "استلم {$done['attempts']} محاولة من سيرفر قاعة في \"{$exam->text('title')}\"", $exam);

        return response()->json($done);
    }

    public function show(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        return Inertia::render('Exams/Monitor/Central', [
            'offline' => (bool) config('offline.edition'),
            'central' => ['enabled' => filled(Setting::get('central_hall_key')), 'key' => session('central_key_shown'),
                'shared' => Exam::where('hall_sync', true)->count()],
            'hall' => ['url' => Setting::get('central_url'), 'has_key' => filled(Setting::get('central_key')), 'last_ok' => Setting::get('central_last_ok'),
                'last_error' => Setting::get('central_last_error') ?: null, 'summary' => Setting::get('central_last_summary')],
            'address' => rtrim(config('app.url'), '/'),
            'messages' => ['url' => Setting::get('msg_url'), 'method' => Setting::get('msg_method') ?: 'POST', 'body' => Setting::get('msg_body'),
                'has_header' => filled(Setting::get('msg_header')), 'country' => Setting::get('msg_country') ?: '964'],
        ]);
    }

    /** The SMS / WhatsApp gateway (hosted site). */
    public function messages(Request $request, \App\Services\Exams\Messenger $messenger): RedirectResponse
    {
        abort_unless($request->user()->isAdmin() && ! config('offline.edition'), 403);
        $data = $request->validate(['url' => ['nullable', 'string', 'max:500', 'starts_with:http://,https://'], 'method' => ['required', 'in:GET,POST'], 'body' => ['nullable', 'string', 'max:2000'],
            'header' => ['nullable', 'string', 'max:500'], 'country' => ['nullable', 'regex:/^\d{1,4}$/'], 'test' => ['nullable', 'string', 'max:30']]);
        if (filled($data['test'] ?? null)) {
            $phone = \App\Services\Exams\Messenger::normalize($data['test']);
            $r = $phone ? $messenger->send([$phone => 'رسالة تجريبية من '.config('app.name')]) : ['sent' => 0];

            return back()->with($r['sent'] ? 'success' : 'error', $r['sent'] ? 'أُرسلت الرسالة التجريبية.' : 'لم تُرسل الرسالة التجريبية؛ راجع الإعدادات.');
        }
        Setting::setMany(['msg_url' => $data['url'] ?? '', 'msg_method' => $data['method'], 'msg_body' => $data['body'] ?? '', 'msg_country' => $data['country'] ?? '964']);
        if (filled($data['header'] ?? null)) {
            Setting::set('msg_header', Crypt::encryptString($data['header']));
        }

        return back()->with('success', 'حُفظت إعدادات الرسائل.');
    }

    public function key(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin() && ! config('offline.edition'), 403);
        if ($request->boolean('off')) {
            Setting::set('central_hall_key', '');

            return back()->with('success', 'أُوقفت المزامنة مع سيرفرات القاعات.');
        }
        $key = Str::random(40);
        Setting::set('central_hall_key', Crypt::encryptString($key));
        ActivityLog::record('exam.central', 'أنشأ مفتاحاً جديداً لسيرفرات القاعات');

        return back()->with('success', 'انسخ المفتاح الآن إلى سيرفرات القاعات؛ لا يظهر مرة أخرى.')->with('central_key_shown', $key);
    }

    public function hallSettings(Request $request, CentralSync $sync): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($request->boolean('now')) {
            $r = $sync->run();

            return back()->with(isset($r['error']) ? 'error' : 'success', isset($r['error']) ? 'تعذّرت المزامنة: '.$r['error']
                : 'أُخذ '.($r['taken'] ?? 0).' امتحاناً وأُرسلت نتائج '.($r['sent'] ?? 0).' امتحاناً.');
        }
        $data = $request->validate(['url' => ['nullable', 'url', 'max:300'], 'key' => ['nullable', 'string', 'max:100']]);
        Setting::set('central_url', $data['url'] ?? '');
        if (filled($data['key'] ?? null)) {
            Setting::set('central_key', Crypt::encryptString($data['key']));
        }
        if (blank($data['url'] ?? null)) {
            Setting::set('central_key', '');
        }

        return back()->with('success', 'حُفظ عنوان السيرفر المركزي.');
    }
}
