<?php

namespace App\Console\Commands;

use App\Models\FileUploadSession;
use Illuminate\Console\Command;

class PurgeStaleUploads extends Command
{
    protected $signature = 'filenest:purge-stale-uploads';

    protected $description = 'تنظيف جلسات الرفع المجزّأ المهجورة وأجزائها المؤقتة';

    public function handle(): int
    {
        $sessions = FileUploadSession::where('created_at', '<', now()->subDay())->get();

        foreach ($sessions as $session) {
            $dir = storage_path('app/filemanager/'.$session->chunkDir());
            if (is_dir($dir)) {
                array_map('unlink', glob("{$dir}/*") ?: []);
                @rmdir($dir);
            }
            $session->delete();
        }

        $this->info("تم تنظيف {$sessions->count()} جلسة رفع متروكة.");

        return self::SUCCESS;
    }
}
