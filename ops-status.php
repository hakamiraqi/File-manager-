<?php
// One-off: the PDF tools on the VPS (self-test, and PDF to Word / Word to PDF through the converters).
use App\Models\File;
use App\Services\Pdf\PdfTools;
Artisan::call('pdf:selftest');
echo Artisan::output();
$t = app(PdfTools::class);
echo 'CAPS: '.json_encode($t->capabilities())."\n";
$dir = storage_path('app/tmp/ops-'.uniqid());
@mkdir($dir, 0775, true);
$pdf = File::where('extension', 'pdf')->where('size', '<', 3e6)->whereNotNull('content_indexed_at')->first();
if ($pdf) {
    $s = microtime(true);
    $how = $t->toWord($pdf, $t->pdfPath($pdf), "$dir/x.docx");
    echo "TO_WORD #{$pdf->id}: $how ".filesize("$dir/x.docx").'B '.round(microtime(true) - $s, 1)."s\n";
}
$doc = File::where('extension', 'docx')->where('size', '<', 3e6)->first();
if ($doc) {
    $s = microtime(true);
    $t->officeToPdf($doc, "$dir/y.pdf");
    echo "OFFICE_TO_PDF #{$doc->id}: ".filesize("$dir/y.pdf").'B '.round(microtime(true) - $s, 1)."s\n";
}
$big = File::where('extension', 'pdf')->whereBetween('size', [3e6, 2e7])->first();
if ($big) {
    $s = microtime(true);
    $t->compress($t->pdfPath($big), 'medium', "$dir/z.pdf");
    echo "COMPRESS #{$big->id}: ".filesize($t->pdfPath($big)).' -> '.filesize("$dir/z.pdf").' '.round(microtime(true) - $s, 1)."s\n";
}
Illuminate\Support\Facades\File::deleteDirectory($dir);
