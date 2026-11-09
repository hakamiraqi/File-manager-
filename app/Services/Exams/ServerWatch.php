<?php

namespace App\Services\Exams;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Watching the server from inside (2026-11-09): every five minutes `exams:watch` checks the disk,
 * memory, database, failed background jobs, the AMC server and the off-site backup, and tells the
 * admins on their phones when something goes wrong (and again when it is fixed): a push
 * notification through ntfy (free app, no account), SMS/WhatsApp through the gateway, or e-mail.
 * A problem is repeated every six hours while it lasts, not every five minutes.
 */
class ServerWatch
{
    private const REPEAT_HOURS = 6;

    /** @return array<string, string> problem key => description (empty when all is well) */
    public function problems(): array
    {
        $out = [];
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free !== false && $total) {
            if ($free / $total < 0.1 || $free < 2 * 1024 ** 3) {
                $out['disk'] = 'المساحة الفارغة على القرص قليلة: '.round($free / 1024 ** 3, 1).' GB ('.round(100 * $free / $total).'%).';
            }
        }
        if (is_readable('/proc/meminfo') && preg_match('/MemTotal:\s+(\d+).*MemAvailable:\s+(\d+)/s', (string) file_get_contents('/proc/meminfo'), $m) && $m[1] > 0) {
            if ($m[2] / $m[1] < 0.05) {
                $out['memory'] = 'الذاكرة شبه ممتلئة: المتاح '.round($m[2] / 1024).' MB فقط.';
            }
        }
        try {
            DB::select('select 1');
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
            if ($failed > 0) {
                $out['jobs'] = "فشلت {$failed} مهمة خلفية في الساعة الأخيرة.";
            }
        } catch (\Throwable $e) {
            $out['database'] = 'قاعدة البيانات لا تستجيب: '.mb_substr($e->getMessage(), 0, 150);
        }
        if (AmcConnector::configured()) {
            $h = app(AmcConnector::class)->health();
            if (! ($h['ok'] ?? false)) {
                $out['amc'] = 'خادم AMC لا يستجيب: '.mb_substr((string) ($h['error'] ?? ''), 0, 150);
            }
        }
        if (OffsiteBackup::configured()) {
            $last = Setting::get('offsite_last_ok');
            if (! $last || now()->diffInHours(\Carbon\Carbon::parse($last)) > 36) {
                $err = trim(preg_replace('/^\S+\s/', '', (string) Setting::get('offsite_last_error')));
                $out['offsite'] = 'لم تُرسل نسخة احتياطية خارجية منذ أكثر من يوم ونصف.'.($err !== '' ? ' آخر خطأ: '.$err : '');
            }
        }

        return $out;
    }

    /** Checks, and sends what is new, still there after six hours, or fixed. */
    public function run(): array
    {
        $now = now()->getTimestamp();
        $state = json_decode((string) Setting::get('watch_state'), true) ?: [];
        $problems = $this->problems();
        $lines = [];
        foreach ($problems as $key => $text) {
            if (! isset($state[$key]) || $now - ($state[$key]['sent'] ?? 0) >= self::REPEAT_HOURS * 3600) {
                $lines[] = '• '.$text;
                $state[$key] = ['since' => $state[$key]['since'] ?? $now, 'sent' => $now];
            }
        }
        foreach (array_diff_key($state, $problems) as $key => $s) {
            $lines[] = '• تم الإصلاح: '.self::label($key);
            unset($state[$key]);
        }
        Setting::setMany(['watch_state' => json_encode($state), 'watch_last' => now()->toIso8601String()]);
        $sent = $lines ? $this->notify(implode("\n", $lines), $problems !== []) : [];
        if ($lines) {
            ActivityLog::record('exam.watch', 'تنبيه السيرفر: '.mb_substr(implode(' ', $lines), 0, 250));
        }

        return ['problems' => $problems, 'sent' => $sent];
    }

    public static function label(string $key): string
    {
        return ['disk' => 'مساحة القرص', 'memory' => 'الذاكرة', 'database' => 'قاعدة البيانات', 'jobs' => 'المهام الخلفية',
            'amc' => 'خادم AMC', 'offsite' => 'النسخ الاحتياطي الخارجي'][$key] ?? $key;
    }

    /** Sends to every channel that is set; returns the ones that took it. */
    public function notify(string $text, bool $urgent = true): array
    {
        $title = config('app.name').' — '.($urgent ? 'تنبيه' : 'عاد للعمل');
        $sent = [];
        if ($ntfy = (string) Setting::get('watch_ntfy')) {
            try {
                $token = Setting::get('watch_ntfy_token') ? Crypt::decryptString((string) Setting::get('watch_ntfy_token')) : null;
                $r = Http::timeout(15)->withHeaders(['Title' => '=?UTF-8?B?'.base64_encode($title).'?=', 'Priority' => $urgent ? 'high' : 'default',
                    'Tags' => $urgent ? 'warning' : 'white_check_mark', 'Click' => rtrim((string) config('app.url'), '/').'/exams/monitor'])
                    ->when($token, fn ($h) => $h->withToken($token))->withBody($text, 'text/plain')->post($ntfy);
                $r->successful() && $sent[] = 'ntfy';
            } catch (\Throwable) {
            }
        }
        $phones = array_filter(array_map([Messenger::class, 'normalize'], preg_split('/[\s,،]+/u', (string) Setting::get('watch_phones'))));
        if ($phones && Messenger::enabled()) {
            try {
                $r = app(Messenger::class)->send(array_fill_keys($phones, $title."\n".$text));
                ($r['sent'] ?? 0) > 0 && $sent[] = 'sms';
            } catch (\Throwable) {
            }
        }
        $emails = array_filter(preg_split('/[\s,،]+/u', (string) Setting::get('watch_email')), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($emails && config('mail.default') !== 'log' && config('mail.default') !== 'array') {
            try {
                Mail::raw($text, fn ($m) => $m->to($emails)->subject($title));
                $sent[] = 'mail';
            } catch (\Throwable) {
            }
        }

        return $sent;
    }
}
