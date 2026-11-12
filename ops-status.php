<?php
// One-off: the PDF stuck in the office editor, and the scanned PDFs' searchable copies.
use App\Models\File;
$f = File::where('name', 'like', 'IMG_2423%')->orderByDesc('id')->get();
foreach ($f as $x) {
    $p = $x->disk()->path($x->path);
    echo "FILE #{$x->id} ext={$x->extension} size={$x->size} exists=".json_encode(is_file($p)).' pages='.trim((string) @shell_exec('qpdf --show-npages '.escapeshellarg($p).' 2>&1')).' updated='.$x->updated_at."\n";
    echo '  info: '.str_replace("\n", ' | ', trim((string) @shell_exec('pdfinfo '.escapeshellarg($p).' 2>&1 | head -12')))."\n";
}
$scans = File::where('extension', 'pdf')->whereNotNull('ocr_status')->get();
$by = $scans->groupBy('ocr_status')->map->count();
echo 'OCR pdf by status: '.json_encode($by)."\n";
$done = $scans->where('ocr_status', 'done');
$with = $done->filter(fn ($x) => $x->searchablePdf() !== null)->count();
echo "SEARCHABLE: {$with} of {$done->count()} done PDFs have a searchable copy\n";
foreach ($done->filter(fn ($x) => $x->searchablePdf() === null)->take(15) as $x) {
    echo "  no copy #{$x->id} pages={$x->ocr_pages} done={$x->ocr_done_at} err=".mb_substr((string) $x->ocr_error, 0, 80)."\n";
}
foreach ($scans->where('ocr_status', 'failed')->take(10) as $x) {
    echo "  failed #{$x->id} ".mb_substr((string) $x->ocr_error, 0, 120)."\n";
}
