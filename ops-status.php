<?php
// One-off (2026-11-12): check page clean-up on the server, then re-read the recognised scanned PDFs
// so each gets a searchable copy (and its text rebuilt without the old doubling).
use App\Models\File;
$engine = app(App\Services\Ocr\OcrEngine::class);
echo 'ENGINE: '.json_encode(array_intersect_key($engine->info(), array_flip(['best', 'searchable_pdf', 'cleanup'])))."\n";
$f = File::find(1125);
exec('pdftoppm -r 300 -gray -png -f 1 -l 1 '.escapeshellarg($f->disk()->path($f->path)).' /tmp/mk');
$png = glob('/tmp/mk*.png')[0];
[$w, $h] = getimagesize($png);
exec($engine->magick().' '.escapeshellarg($png).' -deskew 40% +repage -normalize -gravity center -background white -extent '.$w.'x'.$h.' '.escapeshellarg($png.'.c.png').' 2>&1', $o, $code);
[$w2, $h2] = @getimagesize($png.'.c.png') ?: [0, 0];
echo "MAGICK PNG: exit $code, {$w}x{$h} -> {$w2}x{$h2} ".implode(' ', $o)."\n";
@unlink($png); @unlink($png.'.c.png');

$t = microtime(true);
app(App\Services\Ocr\OcrRunner::class)->process($f);
$f->refresh();
echo "RE-READ #1125 with clean-up: ".($f->searchablePdf() ? 'searchable copy made' : 'NO copy')." in ".round(microtime(true) - $t)."s, ".mb_strlen((string) $f->content_text)." chars\n";

$q = File::where('extension', 'pdf')->where('ocr_status', 'done')->where('ocr_pages', '>', 0)->where('id', '!=', 1125);
echo 'REQUEUE: '.$q->count().' PDFs, '.(clone $q)->sum('ocr_pages')." pages\n";
$n = $q->update(['ocr_status' => 'pending', 'ocr_attempts' => 0, 'ocr_error' => null, 'ocr_priority' => -1]);
echo "REQUEUED: $n (after any never-read files)\n";
echo 'WINDOW: '.json_encode(App\Models\Setting::whereIn('key', ['ocr_days', 'ocr_start', 'ocr_end', 'ocr_all_day_days'])->pluck('value', 'key'))."\n";
