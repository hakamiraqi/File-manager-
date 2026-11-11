<?php
// Find real Arabic scanned pages (most letters Arabic) and run a detached test on them, so a
// slow model run is not cut off with the SSH session. Results land in the trial list.
$picked = [];
foreach (\App\Models\File::where('extension', 'pdf')->where('ocr_status', 'done')->whereRaw('CHAR_LENGTH(content_text) > 3000')->inRandomOrder()->limit(400)->get(['id', 'name', 'content_text']) as $f) {
    $t = mb_substr((string) $f->content_text, 0, 20000);
    $ar = preg_match_all('/\p{Arabic}/u', $t); $la = preg_match_all('/[A-Za-z]/', $t);
    if ($ar > 2000 && $ar > 3 * $la) { $picked[] = $f->id; echo "ARABIC {$f->id} ar=$ar la=$la ".mb_substr($f->name, 0, 60)."\n"; }
    if (count($picked) >= 2) break;
}
file_put_contents('/tmp/qari-files.txt', implode(',', $picked));
$script = <<<'PHP'
<?php
require '/var/www/html/vendor/autoload.php'; $app = require '/var/www/html/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Jobs\QariTrialRun; use Illuminate\Support\Facades\Cache;
$engine = app(\App\Services\Ocr\OcrEngine::class); $qari = app(\App\Services\Ocr\QariClient::class);
foreach (array_filter(explode(',', file_get_contents('/tmp/qari-files.txt'))) as $fid) {
    $f = \App\Models\File::find($fid); $id = substr(md5('ar'.$fid), 0, 12);
    $dir = storage_path('app/private/qari-trial'); @mkdir($dir, 0775, true);
    \Illuminate\Support\Facades\Process::timeout(120)->run([$engine->pdftoppm(), '-png', '-r', '200', '-f', '2', '-l', '2', '-singlefile', \Illuminate\Support\Facades\Storage::disk($f->disk)->path($f->path), "$dir/$id"]);
    if (! is_file("$dir/$id.png")) continue;
    Cache::put(QariTrialRun::key($id), ['id' => $id, 'name' => $f->name, 'page' => 2, 'at' => now()->toIso8601String(), 'engines' => ['tesseract', 'qari-2b'], 'results' => []], now()->addDays(7));
    Cache::put('qari-trial:list', array_slice([$id, ...Cache::get('qari-trial:list', [])], 0, 15), now()->addDays(7));
    (new QariTrialRun($id))->handle($engine, $qari);
    // The same page, smaller picture: how much faster, and is it still right?
    $t = Cache::get(QariTrialRun::key($id));
    try { $r = $qari->read('qari-2b', "$dir/$id.png", 1024); $t['results']['qari-2b@1024'] = ['state' => 'done', ...$r]; } catch (\Throwable $e) { $t['results']['qari-2b@1024'] = ['state' => 'failed', 'error' => $e->getMessage()]; }
    $t['engines'][] = 'qari-2b@1024';
    Cache::put(QariTrialRun::key($id), $t, now()->addDays(7));
}
file_put_contents('/tmp/qari-done.txt', date('c'));
PHP;
file_put_contents('/tmp/qari-ar.php', $script);
@unlink('/tmp/qari-done.txt');
exec('nohup php /tmp/qari-ar.php > /tmp/qari-ar.log 2>&1 &');
echo "STARTED\n";
