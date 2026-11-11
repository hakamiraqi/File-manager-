<?php
// Until the e-library is read (2026-11-10, by request): every day all day, and room for long scanned books.
// Counts only: how far text extraction and recognition have got on the e-library.
use App\Models\File;
use App\Models\Folder;
use Illuminate\Support\Facades\DB;
// One-off (2026-11-11 23:30 Baghdad, by request): OCR load checks back on after today's pause.
App\Models\Setting::setMany(['ocr_max_cpu' => '70', 'ocr_min_ram_mb' => '1536', 'ocr_max_users' => '10']);
echo 'LOAD CHECKS: '.json_encode(App\Models\Setting::whereIn('key', ['ocr_max_cpu', 'ocr_min_ram_mb', 'ocr_max_users', 'ocr_min_disk_gb'])->pluck('value', 'key'))."\n";
$root = Folder::where('name', 'like', 'المكتبة الإلكترونية (الرقمية)%')->whereNull('parent_id')->first();
$ids = [$root->id]; $frontier = [$root->id];
while ($frontier) { $frontier = Folder::whereIn('parent_id', $frontier)->pluck('id')->all(); $ids = array_merge($ids, $frontier); }
$q = fn () => File::whereIn('folder_id', $ids);
echo 'FILES: '.$q()->count()."\n";
echo 'TEXT INDEXED: '.$q()->whereNotNull('content_indexed_at')->count()."\n";
echo 'WITH TEXT: '.$q()->whereRaw('length(content_text) > 100')->count()."\n";
echo 'OCR DONE LAST HOUR: '.$q()->where('ocr_status', 'done')->where('updated_at', '>=', now()->subHour())->count()."\n";
echo 'OCR: '.json_encode($q()->selectRaw('coalesce(ocr_status, "none") s, count(*) n')->groupBy('s')->pluck('n', 's'))."\n";
echo 'JOBS WAITING: '.DB::table('jobs')->count().'  FAILED: '.DB::table('failed_jobs')->count()."\n";
echo 'APP TIME: '.now()->toDateTimeString().' '.config('app.timezone')."\n";
$s = app(App\Services\Ocr\OcrSettings::class)->all();
echo 'OCR LAST: '.json_encode(DB::table('ocr_load_samples')->latest('id')->limit(4)->get(['state','reason','processed','cpu','created_at']))."
";
$p = $q()->where('ocr_status', 'processing')->first(['id','extension','size','updated_at','ocr_attempts']);
echo 'PROCESSING: '.json_encode($p)."
";
echo 'PENDING SIZE MB: '.round($q()->where('ocr_status', 'pending')->sum('size') / 1e6).'  BY TYPE: '.json_encode($q()->where('ocr_status', 'pending')->selectRaw('extension, count(*) n')->groupBy('extension')->pluck('n', 'extension'))."
";
echo 'DONE DETAIL: '.json_encode($q()->where('ocr_status', 'done')->orderByDesc('ocr_done_at')->limit(5)->get(['id', 'size', 'extension', 'ocr_pages', 'ocr_seconds', 'ocr_error'])->map->only(['id', 'extension', 'ocr_pages', 'ocr_seconds', 'ocr_error']))."\n";
echo 'DONE ALL: '.$q()->where('ocr_status', 'done')->count().' FAILED: '.$q()->where('ocr_status', 'failed')->count()."
";
