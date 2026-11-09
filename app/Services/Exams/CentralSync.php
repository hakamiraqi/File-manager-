<?php

namespace App\Services\Exams;

use App\Models\Exams\Exam;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * A hall's own server and the central (hosted) one, whenever the internet is there: the hall
 * takes the exams the central server hands out, and sends back its results. Students then see
 * their results and appeal from home on the central site.
 */
class CentralSync
{
    public function __construct(private Transfer $transfer) {}

    public static function hallKey(): ?string
    {
        return Setting::get('central_hall_key') ? Crypt::decryptString((string) Setting::get('central_hall_key')) : null;
    }

    /** On a hall server: one round of taking exams and sending results. */
    public function run(): array
    {
        $url = rtrim((string) Setting::get('central_url'), '/');
        $key = Setting::get('central_key') ? Crypt::decryptString((string) Setting::get('central_key')) : null;
        if ($url === '' || ! $key) {
            return ['skipped' => true];
        }
        $http = fn () => Http::timeout(60)->acceptJson()->withHeaders(['X-Hall-Key' => $key]);
        $taken = 0;
        $sent = 0;
        try {
            $owner = User::where('role', 'admin')->orderByDesc('is_master_admin')->orderBy('id')->firstOrFail();
            $list = $http()->get($url.'/central/exams')->throw()->json('exams') ?? [];
            foreach ($list as $item) {
                if (! is_string($item['transfer_key'] ?? null) || Exam::where('transfer_key', $item['transfer_key'])->exists()) {
                    continue;
                }
                $package = $http()->get($url.'/central/exams/'.rawurlencode($item['transfer_key']))->throw()->json();
                $this->transfer->importExam($package, $owner);
                $taken++;
            }
            // Results of every exam that came from the central server (or went to it).
            $last = Setting::get('central_last_sent') ?: '1970-01-01T00:00:00Z';
            foreach (Exam::whereNotNull('transfer_key')->whereHas('attempts', fn ($q) => $q->whereNotNull('submitted_at')->where('updated_at', '>=', \Illuminate\Support\Carbon::parse($last)->subMinutes(2)))->get() as $exam) {
                $http()->post($url.'/central/results/'.rawurlencode($exam->transfer_key), $this->transfer->exportResults($exam))->throw();
                $sent++;
            }
            Setting::setMany(['central_last_ok' => now()->toIso8601String(), 'central_last_sent' => now()->toIso8601String(), 'central_last_error' => '',
                'central_last_summary' => "{$taken}|{$sent}"]);

            return ['taken' => $taken, 'sent' => $sent];
        } catch (\Throwable $e) {
            Setting::set('central_last_error', now()->toIso8601String().' — '.mb_substr($e->getMessage(), 0, 300));

            return ['error' => $e->getMessage(), 'taken' => $taken, 'sent' => $sent];
        }
    }
}
