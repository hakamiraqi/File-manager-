<?php
// Qari trial on real pages: Arabic scanned pages from the e-library, each read by Tesseract,
// Qari 2B and Qari 4B; times and the start of each text (counts and short samples only).
use App\Models\File; use App\Jobs\QariTrialRun; use Illuminate\Support\Facades\Cache;
$files = File::where('extension', 'pdf')->where('ocr_status', 'done')
    ->whereRaw("CHAR_LENGTH(content_text) > 2000 AND content_text REGEXP '[ابتثجحخدذرزسشصضطظعغفقكلمنهوي]{4}'")
    ->inRandomOrder()->limit(2)->get(['id', 'name', 'path', 'disk']);
$engine = app(\App\Services\Ocr\OcrEngine::class);
foreach ($files as $f) {
    $id = substr(md5('t'.$f->id), 0, 12);
    $dir = storage_path('app/private/qari-trial'); @mkdir($dir, 0775, true);
    \Illuminate\Support\Facades\Process::timeout(120)->run([$engine->pdftoppm(), '-png', '-r', '200', '-f', '3', '-l', '3', '-singlefile', \Illuminate\Support\Facades\Storage::disk($f->disk)->path($f->path), "$dir/$id"]);
    if (! is_file("$dir/$id.png")) { echo "SKIP {$f->id}\n"; continue; }
    Cache::put(QariTrialRun::key($id), ['id' => $id, 'name' => $f->name, 'page' => 3, 'at' => now()->toIso8601String(),
        'engines' => ['tesseract', 'qari-2b', 'qari-4b'], 'results' => []], now()->addDays(7));
    Cache::put('qari-trial:list', array_slice([$id, ...Cache::get('qari-trial:list', [])], 0, 15), now()->addDays(7));
    (new QariTrialRun($id))->handle($engine, app(\App\Services\Ocr\QariClient::class));
    foreach (Cache::get(QariTrialRun::key($id))['results'] as $e => $r) {
        $t = preg_replace('/\s+/u', ' ', (string) ($r['text'] ?? ''));
        $arab = preg_match_all('/\p{Arabic}/u', $t);
        echo "RESULT file={$f->id} $e {$r['state']} sec=".($r['seconds'] ?? '-')." chars=".mb_strlen($t)." arabic=$arab ptok=".($r['prompt_tokens'] ?? '-')." tok=".($r['tokens'] ?? '-').' '.($r['error'] ?? '')."\n";
        echo "SAMPLE $e: ".mb_substr($t, 0, 160)."\n";
    }
}
