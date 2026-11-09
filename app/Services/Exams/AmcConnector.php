<?php

namespace App\Services\Exams;

use App\Models\Exams\Exam;
use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The exam system's side of the AMC connector (amc-connector/server.py): one AMC project per exam,
 * built from the exam, fed with the scans, and read back as marked attempts.
 */
class AmcConnector
{
    public function __construct(private AutoMultipleChoice $amc) {}

    /** The connector's address and token: set in the app (a hall's own server), or from the environment. */
    public static function config(): array
    {
        $url = Setting::get('amc_url') ?: config('services.amc.url');
        $token = Setting::get('amc_token') ? Crypt::decryptString((string) Setting::get('amc_token')) : config('services.amc.token');

        return [rtrim((string) $url, '/'), (string) $token];
    }

    public static function configured(): bool
    {
        [$url, $token] = self::config();

        return $url !== '' && $token !== '';
    }

    private function http(int $timeout = 60): PendingRequest
    {
        [$url, $token] = self::config();
        if ($url === '' || $token === '') {
            throw new RuntimeException('خادم AMC غير مضبوط.');
        }

        return Http::baseUrl($url)->withToken($token)->timeout($timeout)->acceptJson();
    }

    /** The project's name on the connector: this server's key and the exam. */
    public static function project(Exam $exam): string
    {
        return 'x'.substr(hash_hmac('sha256', 'amc|'.config('app.url'), (string) config('app.key')), 0, 10).'-'.$exam->id;
    }

    public function health(): array
    {
        [$url] = self::config();
        try {
            $r = Http::timeout(8)->acceptJson()->get($url.'/health');

            return $r->successful() ? ['ok' => true] + $r->json() : ['ok' => false, 'error' => 'HTTP '.$r->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Builds the sheets (one named sheet per student) from the exam as it is now. */
    public function build(Exam $exam): array
    {
        $r = $this->http(900)->put('/projects/'.self::project($exam), ['source' => $this->amc->source($exam), 'students' => $this->amc->students($exam),
            'pictures' => collect($this->amc->pictures($exam))->mapWithKeys(fn ($p) => [$p['file'] => base64_encode($p['bytes'])])->all()]);
        if (! $r->successful()) {
            throw new RuntimeException('تعذّر تجهيز أوراق AMC: '.($r->json('error') ?? 'HTTP '.$r->status()).($r->json('log') ? "\n".mb_substr((string) $r->json('log'), -1500) : ''));
        }

        return $r->json();
    }

    public function status(Exam $exam): array
    {
        $r = $this->http(20)->get('/projects/'.self::project($exam).'/status');

        return $r->status() === 404 ? ['state' => 'none'] : $r->throw()->json();
    }

    /** The sheets to print, or the corrected sheet. */
    public function pdf(Exam $exam, string $which = 'subject'): string
    {
        return $this->http(120)->get('/projects/'.self::project($exam).'/'.($which === 'correction' ? 'correction' : 'subject').'.pdf')->throw()->body();
    }

    public function addScan(Exam $exam, string $path, string $name): void
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'scan.pdf';
        $this->http(600)->withBody((string) file_get_contents($path), 'application/octet-stream')
            ->post('/projects/'.self::project($exam).'/scans?name='.rawurlencode($safe))->throw();
    }

    public function clearScans(Exam $exam): void
    {
        $this->http(30)->delete('/projects/'.self::project($exam).'/scans')->throw();
    }

    public function process(Exam $exam): void
    {
        $r = $this->http(30)->post('/projects/'.self::project($exam).'/process');
        if ($r->status() !== 202) {
            throw new RuntimeException($r->json('error') ?? 'HTTP '.$r->status());
        }
    }

    /** The finished results into the exam's attempts. */
    public function import(Exam $exam): array
    {
        $csv = $this->http(60)->get('/projects/'.self::project($exam).'/results.csv')->throw()->body();

        return $this->amc->import($exam, $csv);
    }
}
