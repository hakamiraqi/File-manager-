<?php
// One-off (2026-11-12): why does the accurate OCR model return nothing?
use App\Models\File;
echo "LS:\n".shell_exec('ls -la /usr/share/tessdata-best 2>&1');
echo "LANGS:\n".shell_exec('tesseract --tessdata-dir /usr/share/tessdata-best --list-langs 2>&1');
echo "VERSION: ".trim(shell_exec('tesseract --version 2>&1 | head -1'))."\n";
echo "HEAD ara: ".bin2hex(substr(file_get_contents('/usr/share/tessdata-best/ara.traineddata') ?: '', 0, 16))."\n";
$f = File::find(205);
$pdf = $f->disk()->path($f->path);
exec('pdftoppm -r 300 -f 4 -l 4 -png '.escapeshellarg($pdf).' /tmp/diag');
$png = glob('/tmp/diag*.png')[0] ?? null;
echo "RUN best ara:\n".substr(shell_exec('tesseract '.escapeshellarg($png).' - -l ara --tessdata-dir /usr/share/tessdata-best 2>&1') ?? '', 0, 400)."\n";
echo "RUN best ara+eng (stderr only):\n".shell_exec('tesseract '.escapeshellarg($png).' - -l ara+eng --tessdata-dir /usr/share/tessdata-best 2>&1 >/dev/null')."\n";
foreach (File::whereIn('id', [1, 2, 10, 205, 226])->get(['id', 'content_text', 'ocr_pages']) as $x) {
    echo "TEXT #{$x->id}: ".mb_strlen((string) $x->content_text)." chars\n";
}
@unlink($png);
