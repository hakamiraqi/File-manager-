<?php
// Puts the packed backup in the main admin's files (a private «نسخ احتياطية» folder) to download it.
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
$admin = User::where('email', 'admin@filenest.test')->firstOrFail();
$rel = getenv('PACK_REL');
$folder = Folder::firstOrCreate(['user_id' => $admin->id, 'parent_id' => null, 'name' => 'نسخ احتياطية للسيرفر بدون إنترنت']);
$path = Storage::disk(config('filenest.disk'))->path($rel);
$f = File::create(['user_id' => $admin->id, 'folder_id' => $folder->id, 'name' => getenv('PACK_NAME'), 'disk' => config('filenest.disk'), 'path' => $rel,
    'extension' => 'zip', 'mime_type' => 'application/zip', 'size' => filesize($path)]);
echo "REGISTERED: file {$f->id} in folder {$folder->id}, ".round($f->size / 1e9, 2)." GB\n";
