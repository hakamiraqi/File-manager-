<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Exam;
use App\Models\Setting;
use App\Services\Exams\AmcConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto Multiple Choice run by the server (the AMC connector): the teacher builds the sheets, prints
 * them, uploads the scans and gets the marks, without AMC on their own computer.
 */
class AmcServerController extends Controller
{
    public function __construct(private AmcConnector $amc) {}

    private function guard(Request $request, Exam $exam): void
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        abort_unless(AmcConnector::configured(), 404, 'خادم AMC غير مضبوط.');
    }

    private function attempt(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function status(Request $request, Exam $exam): JsonResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        if (! AmcConnector::configured()) {
            return response()->json(['configured' => false]);
        }
        $health = $this->amc->health();

        return response()->json(['configured' => true, 'health' => $health] + ($health['ok'] ? ['project' => rescue(fn () => $this->amc->status($exam), ['state' => 'unknown'], false)] : []));
    }

    public function build(Request $request, Exam $exam): JsonResponse
    {
        $this->guard($request, $exam);

        return $this->attempt(function () use ($exam) {
            $r = $this->amc->build($exam);
            ActivityLog::record('exam.amc_build', "جهّز أوراق AMC لـ \"{$exam->text('title')}\"", $exam);

            return $r;
        });
    }

    public function pdf(Request $request, Exam $exam): Response
    {
        $this->guard($request, $exam);
        $which = $request->query('which') === 'correction' ? 'correction' : 'subject';
        try {
            $pdf = $this->amc->pdf($exam, $which);
        } catch (\Throwable $e) {
            abort(404, 'الأوراق غير جاهزة بعد.');
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="amc-'.$which.'-'.$exam->id.'.pdf"']);
    }

    public function scan(Request $request, Exam $exam): JsonResponse
    {
        $this->guard($request, $exam);
        $request->validate(['file' => ['required', 'file', 'max:32768', 'extensions:pdf,png,jpg,jpeg,tif,tiff']]);
        $file = $request->file('file');

        return $this->attempt(function () use ($exam, $file) {
            $this->amc->addScan($exam, $file->getRealPath(), $file->getClientOriginalName());

            return ['ok' => true];
        });
    }

    public function clearScans(Request $request, Exam $exam): JsonResponse
    {
        $this->guard($request, $exam);

        return $this->attempt(fn () => tap(['ok' => true], fn () => $this->amc->clearScans($exam)));
    }

    public function process(Request $request, Exam $exam): JsonResponse
    {
        $this->guard($request, $exam);

        return $this->attempt(fn () => tap(['ok' => true], fn () => $this->amc->process($exam)));
    }

    public function import(Request $request, Exam $exam): JsonResponse
    {
        $this->guard($request, $exam);

        return $this->attempt(function () use ($exam) {
            $r = $this->amc->import($exam);
            ActivityLog::record('exam.amc', "أدخل نتائج {$r['attempts']} طالباً من خادم AMC في \"{$exam->text('title')}\"", $exam);

            return $r;
        });
    }

    /** Where the connector is, for a hall's own server (the hosted one has it from its environment). */
    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['url' => ['nullable', 'string', 'max:300', 'starts_with:http://,https://'], 'token' => ['nullable', 'string', 'max:200']]);
        Setting::set('amc_url', $data['url'] ?? '');
        if (filled($data['token'] ?? null)) {
            Setting::set('amc_token', Crypt::encryptString($data['token']));
        }
        if (blank($data['url'] ?? null)) {
            Setting::set('amc_token', '');
        }
        $health = $this->amc->health();

        return back()->with($health['ok'] ? 'success' : 'error', $health['ok'] ? 'خادم AMC يعمل: '.($health['amc'] ?? '') : 'لم يستجب خادم AMC: '.($health['error'] ?? ''));
    }
}
