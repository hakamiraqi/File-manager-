<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\StorageQuotaService;
use Illuminate\Console\Command;

class PurgeExpiredTrash extends Command
{
    protected $signature = 'filenest:purge-trash';

    protected $description = 'حذف نهائي للملفات والمجلدات الموجودة في سلة المحذوفات منذ أكثر من المدة المسموح بها';

    public function handle(StorageQuotaService $quota): int
    {
        $days = (int) config('filenest.trash_retention_days');
        $cutoff = now()->subDays($days);

        $files = File::onlyTrashed()->where('deleted_at', '<', $cutoff)->get();
        foreach ($files as $file) {
            $user = User::find($file->user_id);
            $file->disk()->delete($file->path);
            if ($user) {
                $quota->decrement($user, $file->size);
            }
            $file->forceDelete();
        }

        $folders = Folder::onlyTrashed()->where('deleted_at', '<', $cutoff)->get();
        foreach ($folders as $folder) {
            $folder->forceDelete();
        }

        $this->info("تم حذف {$files->count()} ملف و{$folders->count()} مجلد نهائياً.");

        return self::SUCCESS;
    }
}
