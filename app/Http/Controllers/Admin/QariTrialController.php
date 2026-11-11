<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\QariTrialRun;
use App\Services\Ocr\OcrEngine;
use App\Services\Ocr\QariClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «تجربة Qari» (2026-11-11, by request): a page of its own, apart from the reading the library
 * already does, to see whether Qari reads Arabic pages better than Tesseract and how long it
 * takes on this server, before deciding to use it. Main admin only.
 */
class QariTrialController extends Controller
{
    private const LIST = 'qari-trial:list';

    private function only(Request $request): void
    {
        abort_unless($request->user()?->is_master_admin, 403, 'هذه الصفحة للمدير الرئيسي فقط.');
    }

    public function index(Request $request, QariClient $qari): Response
    {
        $this->only($request);

        return Inertia::render('Admin/QariTrial', [
            'models' => collect(QariClient::MODELS)->map(fn ($m, $key) => ['key' => $key, 'label' => $m[0], 'up' => $qari->health($key)])->values(),
            'trials' => collect(Cache::get(self::LIST, []))->map(fn ($id) => Cache::get(QariTrialRun::key($id)))->filter()->values(),
        ]);
    }

    public function store(Request $request, OcrEngine $engine): JsonResponse
    {
        $this->only($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:pdf,png,jpg,jpeg,webp,tif,tiff,bmp'],
            'page' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'engines' => ['required', 'array', 'min:1'],
            'engines.*' => ['in:tesseract,tesseract-best,'.implode(',', array_keys(QariClient::MODELS))],
        ]);

        $id = Str::lower(Str::random(12));
        $dir = storage_path('app/private/qari-trial');
        @mkdir($dir, 0775, true);
        $file = $request->file('file');
        $page = (int) ($data['page'] ?? 1);
        $png = "$dir/$id.png";

        if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
            $bin = $engine->pdftoppm() ?? abort(422, 'pdftoppm غير مثبت.');
            $src = $file->getRealPath();
            $r = Process::timeout(120)->run([$bin, '-png', '-r', '200', '-f', (string) $page, '-l', (string) $page, '-singlefile', $src, "$dir/$id"]);
            abort_unless($r->successful() && is_file($png), 422, 'تعذّر تحويل صفحة الـPDF إلى صورة (تأكد من رقم الصفحة).');
        } else {
            $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
            abort_if($img === false, 422, 'تعذّرت قراءة الصورة.');
            imagepng($img, $png);
            imagedestroy($img);
        }

        $trial = [
            'id' => $id,
            'name' => $file->getClientOriginalName(),
            'page' => $page,
            'at' => now()->toIso8601String(),
            'engines' => array_values($data['engines']),
            'results' => collect($data['engines'])->mapWithKeys(fn ($e) => [$e => ['state' => 'waiting']])->all(),
        ];
        Cache::put(QariTrialRun::key($id), $trial, now()->addDays(7));
        Cache::put(self::LIST, array_slice([$id, ...Cache::get(self::LIST, [])], 0, 15), now()->addDays(7));
        QariTrialRun::dispatch($id);

        return response()->json($trial);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->only($request);

        return response()->json(Cache::get(QariTrialRun::key($id)) ?? abort(404));
    }

    public function image(Request $request, string $id): BinaryFileResponse
    {
        $this->only($request);
        abort_unless(preg_match('/^[a-z0-9]{12}$/', $id), 404);
        $path = storage_path("app/private/qari-trial/$id.png");
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
    }
}
