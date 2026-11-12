<?php
// One-off (2026-11-12): has the accurate Arabic model improved recognition?
// 1) what OCR did since it went live; 2) the same pages read by the fast and the accurate model side by side.
// Counts and scores only, no text or names.
use App\Models\File;

$since = '2026-11-11 16:00:00';
$done = File::where('ocr_status', 'done')->where('ocr_done_at', '>=', $since)->get(['id', 'ocr_pages', 'ocr_seconds', 'ocr_done_at']);
echo 'OCR SINCE ACCURATE MODEL: '.$done->count().' files, '.$done->sum('ocr_pages').' pages, '.$done->sum('ocr_seconds')."s\n";
foreach ($done as $f) {
    echo "  #{$f->id}: {$f->ocr_pages} pages, {$f->ocr_seconds}s, ".round($f->ocr_seconds / max(1, $f->ocr_pages), 1)."s/page, at {$f->ocr_done_at}\n";
}
echo 'STATUS NOW: '.json_encode(File::selectRaw('coalesce(ocr_status, "none") s, count(*) n')->groupBy('s')->pluck('n', 's'))."\n";

// Side by side on pages of the longest re-read file (falls back to any OCR'd PDF).
$file = $done->sortByDesc('ocr_pages')->first() ?? File::where('ocr_status', 'done')->where('ocr_pages', '>', 3)->first();
$file = File::find($file->id);
$pdf = $file->disk()->path($file->path);
$dir = sys_get_temp_dir().'/ocr-compare-'.getmypid();
@mkdir($dir);
exec('pdftoppm -r 300 -f 3 -l 5 -png '.escapeshellarg($pdf).' '.escapeshellarg($dir.'/p'));
$pages = glob($dir.'/p*.png');
echo "COMPARE: file #{$file->id}, pages ".count($pages)." (3-5 at 300 dpi)\n";

$score = function (string $tsv): array {
    $conf = [];
    $words = 0;
    $clean = 0;
    foreach (array_slice(explode("\n", trim($tsv)), 1) as $line) {
        $c = explode("\t", $line);
        if (count($c) < 12 || trim($c[11]) === '' || (float) $c[10] < 0) continue;
        $words++;
        $conf[] = (float) $c[10];
        if (preg_match('/^(\p{Arabic}{2,}|[A-Za-z]{2,}|[0-9٠-٩]+)[.,،:؛)]?$/u', trim($c[11]))) $clean++;
    }
    return ['words' => $words, 'conf' => $words ? round(array_sum($conf) / $words, 1) : 0, 'clean%' => $words ? round(100 * $clean / $words, 1) : 0];
};

foreach (['fast' => '', 'accurate' => '--tessdata-dir /usr/share/tessdata-best'] as $label => $flag) {
    $total = ['words' => 0, 'conf' => [], 'clean%' => []];
    $t = microtime(true);
    foreach ($pages as $png) {
        $tsv = shell_exec('timeout 300 tesseract '.escapeshellarg($png).' - -l ara+eng --psm 3 '.$flag.' tsv 2>/dev/null') ?? '';
        $s = $score($tsv);
        $total['words'] += $s['words'];
        $total['conf'][] = $s['conf'];
        $total['clean%'][] = $s['clean%'];
    }
    $n = max(1, count($pages));
    echo strtoupper($label).': words '.$total['words'].', mean confidence '.round(array_sum($total['conf']) / $n, 1).'%, clean words '.round(array_sum($total['clean%']) / $n, 1).'%, '.round((microtime(true) - $t) / $n, 1)."s/page\n";
}
array_map('unlink', glob($dir.'/*'));
@rmdir($dir);
