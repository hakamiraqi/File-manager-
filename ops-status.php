<?php
// One-off (2026-11-12): fast vs accurate OCR on the same pages (plain text output), with a short sample.
use App\Models\File;
$f = File::find(205);
$pdf = $f->disk()->path($f->path);
exec('pdftoppm -r 300 -f 3 -l 6 -png '.escapeshellarg($pdf).' /tmp/cmp');
$pages = glob('/tmp/cmp*.png');
echo "COMPARE: file #205, ".count($pages)." pages at 300 dpi\n";
$clean = function (string $text): array {
    $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    $ok = 0;
    foreach ($tokens as $t) {
        if (preg_match('/^[(«"]?(\p{Arabic}{2,}|[A-Za-z]{2,}|[0-9٠-٩]+)[.,،:؛)»"]?$/u', $t) && ! str_contains($t, '�')) $ok++;
    }
    return [count($tokens), count($tokens) ? round(100 * $ok / count($tokens), 1) : 0];
};
foreach (['FAST' => '', 'ACCURATE' => '--tessdata-dir /usr/share/tessdata-best '] as $label => $flag) {
    $t = microtime(true);
    $all = '';
    $first = null;
    foreach ($pages as $png) {
        $txt = shell_exec('tesseract '.$flag.escapeshellarg($png).' - -l ara+eng 2>/dev/null') ?? '';
        $first ??= $txt;
        $all .= "\n".$txt;
    }
    [$words, $pct] = $clean($all);
    echo "$label: $words words, clean words $pct%, ".round((microtime(true) - $t) / max(1, count($pages)), 1)."s/page\n";
    echo "$label SAMPLE: ".mb_substr(preg_replace('/\s+/u', ' ', trim($first)), 0, 160)."\n";
}
array_map('unlink', $pages);
