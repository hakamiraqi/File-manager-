<?php
// One-off (2026-11-12): 1) clean-up before OCR with ImageMagick / unpaper; 2) a real searchable PDF.
use App\Models\File;
echo 'TOOLS: '.trim(shell_exec('for t in magick convert unpaper qpdf; do command -v $t >/dev/null && printf "%s " $t; done') ?? '')."\n";
$clean = function (string $text): array {
    $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    $ok = 0;
    foreach ($tokens as $t) {
        if (preg_match('/^[(«"]?(\p{Arabic}{2,}|[A-Za-z]{2,}|[0-9٠-٩]+)[.,،:؛)»"]?$/u', $t) && ! str_contains($t, '�')) $ok++;
    }
    return [count($tokens), $ok, count($tokens) ? round(100 * $ok / count($tokens), 1) : 0];
};
$magick = trim(shell_exec('command -v magick || command -v convert') ?? '');
$variants = [
    'A current' => null,
    'E deskew+normalize' => fn ($in, $out) => "$magick ".escapeshellarg($in)." -deskew 40% -normalize ".escapeshellarg($out),
    'F deskew+shadow removal' => fn ($in, $out) => "$magick ".escapeshellarg($in)." -deskew 40% \\( +clone -blur 0x20 \\) -compose Divide_Src -composite -normalize ".escapeshellarg($out),
    'U unpaper' => fn ($in, $out) => 'unpaper -q --overwrite '.escapeshellarg($in).' '.escapeshellarg($out),
];
foreach ([[205, 3, 6], [205, 20, 23], [226, 3, 6]] as [$id, $from, $to]) {
    $f = File::find($id);
    exec("pdftoppm -r 300 -gray -f $from -l $to ".escapeshellarg($f->disk()->path($f->path)).' /tmp/vv');
    $pages = glob('/tmp/vv*.pgm');
    echo "FILE #$id pages $from-$to\n";
    foreach ($variants as $name => $prep) {
        $t = microtime(true);
        $all = '';
        foreach ($pages as $p) {
            $in = $p;
            if ($prep) {
                $in = $p.'.out.pgm';
                exec($prep($p, $in).' 2>&1', $o, $code);
                if (! is_file($in)) { echo "  $name: prep failed ($code) ".implode(' ', array_slice($o, -2))."\n"; continue 2; }
            }
            $all .= "\n".(shell_exec('tesseract --tessdata-dir /usr/share/tessdata-best '.escapeshellarg($in).' - -l ara+eng 2>/dev/null') ?? '');
            if ($in !== $p) @unlink($in);
        }
        [$w, $ok, $pct] = $clean($all);
        echo "  VARIANT $name: $w words, $ok clean ($pct%), ".round((microtime(true) - $t) / max(1, count($pages)), 1)."s/page\n";
    }
    array_map('unlink', $pages);
}

// A real searchable copy: the smallest recognised scanned PDF with a few pages.
$file = File::where('extension', 'pdf')->where('ocr_status', 'done')->whereBetween('ocr_pages', [2, 12])->orderBy('ocr_pages')->first();
if ($file) {
    $t = microtime(true);
    app(App\Services\Ocr\OcrRunner::class)->process($file);
    $file->refresh();
    $copy = $file->searchablePdf();
    $orig = $file->disk()->path($file->path);
    $count = fn ($p) => mb_strlen(trim((string) shell_exec('pdftotext -q '.escapeshellarg($p).' - 2>/dev/null')));
    echo "SEARCHABLE #{$file->id}: ".($copy ? 'made' : 'NOT made')." in ".round(microtime(true) - $t)."s, pages {$file->ocr_pages}, status {$file->ocr_status}\n";
    if ($copy) {
        echo "  copyable text: original ".$count($orig)." chars -> searchable ".$count($copy)." chars; size ".round(filesize($orig) / 1048576, 2)." MB -> ".round(filesize($copy) / 1048576, 2)." MB\n";
    }
}
echo 'OCR PDFS DONE: '.File::where('extension', 'pdf')->where('ocr_status', 'done')->where('ocr_pages', '>', 0)->count().', pages '.File::where('extension', 'pdf')->where('ocr_status', 'done')->sum('ocr_pages')."\n";
