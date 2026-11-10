<?php
// One-off: copies a downloaded folder tree into FileNest as folders and files owned by the main admin.
// Re-runnable: folders are matched by name under their parent, and a file already there is skipped.
use App\Jobs\ExtractFileText;
use App\Jobs\GenerateThumbnail;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\ExtensionFilter;
use App\Services\StorageQuotaService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$src = getenv('IMPORT_DIR');
$rootName = getenv('IMPORT_ROOT_NAME');
$user = User::where('email', 'admin@filenest.test')->firstOrFail();
$disk = Storage::disk(config('filenest.disk'));
$disk->makeDirectory((string) $user->id);
$filter = app(ExtensionFilter::class);
$quota = app(StorageQuotaService::class);
$stats = ['folders' => 0, 'files' => 0, 'skipped_existing' => 0, 'skipped_type' => [], 'bytes' => 0];

$folderFor = function (?int $parentId, string $name) use ($user, &$stats) {
    $f = Folder::where('user_id', $user->id)->where('parent_id', $parentId)->where('name', $name)->first();
    if ($f) return $f;
    $stats['folders']++;
    return Folder::create(['user_id' => $user->id, 'parent_id' => $parentId, 'name' => $name]);
};
$walk = function (string $dir, int $folderId) use (&$walk, $folderFor, $user, $disk, $filter, $quota, &$stats) {
    $entries = array_values(array_diff(scandir($dir), ['.', '..']));
    natcasesort($entries);
    foreach ($entries as $name) {
        $path = $dir.'/'.$name;
        if (is_dir($path)) { $walk($path, $folderFor($folderId, $name)->id); continue; }
        if (str_ends_with($name, '.part')) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== '' && ! $filter->isAllowed($ext)) { $stats['skipped_type'][$ext] = ($stats['skipped_type'][$ext] ?? 0) + 1; continue; }
        if (File::where('folder_id', $folderId)->where('name', $name)->exists()) { $stats['skipped_existing']++; continue; }
        $stored = $user->id.'/'.Str::uuid().($ext !== '' ? '.'.$ext : '');
        copy($path, $disk->path($stored));
        $size = filesize($disk->path($stored));
        $file = File::create(['user_id' => $user->id, 'folder_id' => $folderId, 'name' => $name, 'disk' => config('filenest.disk'), 'path' => $stored,
            'extension' => $ext ?: null, 'mime_type' => mime_content_type($disk->path($stored)) ?: 'application/octet-stream', 'size' => $size]);
        $quota->increment($user, $size);
        ExtractFileText::dispatch($file->id);
        GenerateThumbnail::dispatch($file->id);
        $stats['files']++; $stats['bytes'] += $size;
        if ($stats['files'] % 100 === 0) echo "  imported {$stats['files']} files\n";
    }
};
$root = $folderFor(null, $rootName);
$walk($src, $root->id);
ActivityLog::record('folder.created', "استيراد المكتبة الإلكترونية من Google Drive ({$stats['files']} ملف)", $root);
echo 'IMPORT: '.json_encode($stats, JSON_UNESCAPED_UNICODE)."\n";
