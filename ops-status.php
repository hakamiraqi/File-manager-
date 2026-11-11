<?php
// One-off (2026-11-11): accurate Arabic model is live; re-read the files that were read with the fast one and are mostly Arabic.
// Counts only.
use App\Models\File;
$info = app(App\Services\Ocr\OcrEngine::class)->info();
echo 'ENGINE: '.json_encode(['best' => $info['best'], 'version' => $info['version'], 'langs' => $info['languages']])."\n";
echo 'QUALITY SETTING: '.json_encode(App\Services\Ocr\OcrSettings::get('ocr_quality'))."\n";
// Arabic letters are two bytes in UTF-8 and Latin ones one, so bytes minus characters ≈ Arabic letters.
$arabic = fn () => File::where('ocr_status', 'done')->where('ocr_pages', '>', 0)
    ->whereRaw('char_length(content_text) > 200')
    ->whereRaw('(length(content_text) - char_length(content_text)) > 0.5 * char_length(content_text)');
echo 'DONE OCR TOTAL: '.File::where('ocr_status', 'done')->where('ocr_pages', '>', 0)->count().'  MOSTLY ARABIC: '.$arabic()->count()."\n";
if ($info['best']) {
    $n = $arabic()->update(['ocr_status' => 'pending', 'ocr_attempts' => 0, 'ocr_error' => null, 'ocr_priority' => -1]);
    echo "REQUEUED (after the unread ones): $n\n";
} else {
    echo "NOT REQUEUED: accurate model missing\n";
}
echo 'PENDING NOW: '.File::where('ocr_status', 'pending')->count().'  PROCESSING: '.File::where('ocr_status', 'processing')->count()."\n";
