<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\OcrLoadSample;
use App\Services\Notify;
use App\Services\Ocr\OcrSettings;
use Illuminate\Console\Command;

/** Morning summary of last night's text recognition for the admins, and log clean-up. */
class OcrReport extends Command
{
    protected $signature = 'ocr:report';

    protected $description = 'Send the morning text recognition report and prune the load log';

    public function handle(): int
    {
        OcrLoadSample::where('created_at', '<', now()->subDays(7))->delete();

        if (! OcrSettings::enabled() || OcrSettings::get('ocr_report') !== '1') {
            return self::SUCCESS;
        }
        $since = now()->subDay();
        $done = File::where('ocr_status', 'done')->where('ocr_done_at', '>=', $since)->count();
        $failed = File::where('ocr_status', 'failed')->where('updated_at', '>=', $since)->count();
        $pending = File::where('ocr_status', 'pending')->count();
        if ($done === 0 && $failed === 0 && $pending === 0) {
            return self::SUCCESS;
        }
        $busy = OcrLoadSample::where('created_at', '>=', $since)->where('state', 'busy')->count();

        $body = "أُنجز {$done} ملف".($failed ? "، وتعذر {$failed}" : '')."، والمتبقي {$pending}."
            .($busy ? " توقف {$busy} دقيقة بسبب انشغال الحاسوب." : '');
        Notify::to(Notify::admins('can_manage_settings'), 'admin', 'ocr_report', 'تقرير التعرف على النصوص', $body, route('admin.ocr.index', [], false));
        $this->line($body);

        return self::SUCCESS;
    }
}
