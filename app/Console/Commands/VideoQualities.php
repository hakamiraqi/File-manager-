<?php

namespace App\Console\Commands;

use App\Jobs\TranscodeVideoQualities;
use App\Models\File;
use App\Services\VideoTranscoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Queues the videos whose lower qualities were never made (2026-11-13): only some upload
 * paths asked for them, so most videos had the original only. A few at a time, and never
 * while earlier ones are still waiting, so the server is not swamped.
 */
class VideoQualities extends Command
{
    protected $signature = 'videos:qualities {--limit=2}';

    protected $description = 'Queue the lower video qualities that are missing';

    public function handle(VideoTranscoder $transcoder): int
    {
        if (! $transcoder->isEnabled() || ! $transcoder->ffmpegAvailable()) {
            $this->line('off or no ffmpeg');

            return self::SUCCESS;
        }
        if (DB::table('jobs')->where('payload', 'like', '%TranscodeVideoQualities%')->exists()) {
            $this->line('still busy');

            return self::SUCCESS;
        }

        $files = self::candidates()->limit(max(1, (int) $this->option('limit')))->get(['id']);
        foreach ($files as $file) {
            // Marked now so a video ffmpeg cannot read is not queued again every quarter hour.
            File::whereKey($file->id)->update(['video_checked_at' => now()]);
            TranscodeVideoQualities::dispatch($file->id);
        }
        $this->info('queued '.$files->count());

        return self::SUCCESS;
    }

    public static function candidates()
    {
        return File::query()->where('mime_type', 'like', 'video/%')->whereNull('video_checked_at')->orderByDesc('id');
    }
}
