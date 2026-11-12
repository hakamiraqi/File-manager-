<?php
// One-off (2026-11-12): does cleaning the page image before OCR help? Same 4 pages of file #205, accurate model.
use App\Models\File;
echo 'TOOLS: '.trim(shell_exec('for t in convert magick unpaper; do command -v $t >/dev/null && printf "%s " $t; done') ?? '')."\n";
$f = File::find(205);
$pdf = $f->disk()->path($f->path);
$clean = function (string $text): array {
    $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    $ok = 0;
    foreach ($tokens as $t) {
        if (preg_match('/^[(«"]?(\p{Arabic}{2,}|[A-Za-z]{2,}|[0-9٠-٩]+)[.,،:؛)»"]?$/u', $t) && ! str_contains($t, '�')) $ok++;
    }
    return [count($tokens), $ok, count($tokens) ? round(100 * $ok / count($tokens), 1) : 0];
};
$best = '--tessdata-dir /usr/share/tessdata-best';
$hasMagick = trim(shell_exec('command -v magick || command -v convert') ?? '');
$variants = [
    'A current (300dpi)' => ['dpi' => 300, 'prep' => null, 'opts' => ''],
    'B Sauvola threshold' => ['dpi' => 300, 'prep' => null, 'opts' => '-c thresholding_method=2'],
    'C 400dpi' => ['dpi' => 400, 'prep' => null, 'opts' => ''],
    'D psm 6 (one block)' => ['dpi' => 300, 'prep' => null, 'opts' => '--psm 6'],
];
if ($hasMagick) {
    $variants['E deskew+normalize'] = ['dpi' => 300, 'prep' => '-colorspace Gray -deskew 40% -normalize', 'opts' => ''];
    $variants['F deskew+adaptive B/W'] = ['dpi' => 300, 'prep' => '-colorspace Gray -deskew 40% -normalize -lat 31x31-8%', 'opts' => ''];
    $variants['G F + Sauvola'] = ['dpi' => 300, 'prep' => '-colorspace Gray -deskew 40% -normalize -lat 31x31-8%', 'opts' => '-c thresholding_method=2'];
}
$rendered = [];
foreach ($variants as $name => $v) {
    $key = $v['dpi'];
    if (! isset($rendered[$key])) {
        exec("pdftoppm -r $key -f 3 -l 6 -png ".escapeshellarg($pdf)." /tmp/pp$key");
        $rendered[$key] = glob("/tmp/pp$key*.png");
    }
    $t = microtime(true);
    $all = '';
    $first = null;
    foreach ($rendered[$key] as $png) {
        $in = $png;
        if ($v['prep']) {
            $in = $png.'.prep.png';
            exec(escapeshellcmd($hasMagick).' '.escapeshellarg($png).' '.$v['prep'].' '.escapeshellarg($in));
        }
        $txt = shell_exec("tesseract $best ".escapeshellarg($in)." - -l ara+eng {$v['opts']} 2>/dev/null") ?? '';
        $first ??= $txt;
        $all .= "\n".$txt;
        if ($in !== $png) @unlink($in);
    }
    [$words, $ok, $pct] = $clean($all);
    echo "VARIANT $name: $words words, $ok clean ($pct%), ".round((microtime(true) - $t) / 4, 1)."s/page\n";
    echo "  SAMPLE: ".mb_substr(preg_replace('/\s+/u', ' ', trim($first)), 0, 140)."\n";
}
foreach ($rendered as $files) array_map('unlink', $files);
