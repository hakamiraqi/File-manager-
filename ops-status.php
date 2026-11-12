<?php
// One-off: scanned books that were read only in part get read again, so they get their searchable copy.
use App\Models\File;
$n = 0;
foreach (File::where('extension', 'pdf')->where('ocr_status', 'done')->get() as $f) {
    if ($f->searchablePdf() === null && (int) $f->ocr_pages > 0) {
        $f->forceFill(['ocr_status' => 'pending', 'ocr_attempts' => 0, 'ocr_error' => null])->save();
        echo "requeued #{$f->id}\n";
        $n++;
    }
}
echo "REQUEUED {$n}\n";
echo 'OCR pdf by status: '.json_encode(File::where('extension', 'pdf')->whereNotNull('ocr_status')->get()->groupBy('ocr_status')->map->count())."\n";
Artisan::call('pdf:selftest');
echo Artisan::output();
