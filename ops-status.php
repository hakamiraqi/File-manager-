<?php
// Counts only: how far text extraction and recognition have got on the e-library.
use App\Models\File;
use App\Models\Folder;
use Illuminate\Support\Facades\DB;
$root = Folder::where('name', 'like', 'المكتبة الإلكترونية (الرقمية)%')->whereNull('parent_id')->first();
$ids = [$root->id]; $frontier = [$root->id];
while ($frontier) { $frontier = Folder::whereIn('parent_id', $frontier)->pluck('id')->all(); $ids = array_merge($ids, $frontier); }
$q = fn () => File::whereIn('folder_id', $ids);
echo 'FILES: '.$q()->count()."\n";
echo 'TEXT INDEXED: '.$q()->whereNotNull('content_indexed_at')->count()."\n";
echo 'WITH TEXT: '.$q()->whereRaw('length(content_text) > 100')->count()."\n";
echo 'OCR: '.json_encode($q()->selectRaw('coalesce(ocr_status, "none") s, count(*) n')->groupBy('s')->pluck('n', 's'))."\n";
echo 'JOBS WAITING: '.DB::table('jobs')->count().'  FAILED: '.DB::table('failed_jobs')->count()."\n";
echo 'APP TIME: '.now()->toDateTimeString().' '.config('app.timezone')."\n";
$s = app(App\Services\Ocr\OcrSettings::class)->all();
echo 'OCR SETTINGS: '.json_encode($s)."\n";
