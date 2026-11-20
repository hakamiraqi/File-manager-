<?php

namespace App\Services\Exams\Sis;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * «ربط نظام الطلبة» (2026-11-20): this exams system is a connector of SIS. The SIS admin connects
 * «نظام الامتحانات» there and gets a key; it is pasted here with SIS's connector address
 * (https://<sis>/api/connectors/v1/exams). SIS decides which tools are on: rosters (courses and
 * their students) and marks_inbox (marks go to the course teacher there to accept).
 */
class SisLink
{
    public static function url(): string
    {
        return rtrim((string) Setting::get('sis_url'), '/');
    }

    public static function key(): ?string
    {
        $v = Setting::get('sis_key');
        if (blank($v)) {
            return null;
        }
        try {
            return Crypt::decryptString((string) $v);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function connected(): bool
    {
        return self::url() !== '' && self::key() !== null;
    }

    /** Tools SIS said are on, as of the last contact. */
    public static function tools(): array
    {
        return json_decode((string) Setting::get('sis_tools'), true) ?: [];
    }

    public static function toolOn(string $tool): bool
    {
        return self::connected() && in_array($tool, self::tools(), true);
    }

    /** For the pages: is it connected, and which tools may be used. */
    public static function forPage(): array
    {
        return ['connected' => self::connected(), 'rosters' => self::toolOn('rosters'), 'marks' => self::toolOn('marks_inbox')];
    }

    public static function connect(string $url, string $key): array
    {
        $manifest = (new self)->call('get', 'manifest', [], rtrim($url, '/'), $key);
        if (($manifest['system'] ?? null) !== 'sis') {
            throw new RuntimeException('العنوان لا يعود لنظام الطلبة.');
        }
        Setting::setMany(['sis_url' => rtrim($url, '/'), 'sis_key' => Crypt::encryptString($key), 'sis_error' => '']);
        self::remember($manifest);

        return $manifest;
    }

    public static function disconnect(): void
    {
        Setting::setMany(['sis_url' => '', 'sis_key' => '', 'sis_tools' => '', 'sis_university' => '', 'sis_error' => '']);
    }

    /** Ask SIS again which tools are on. */
    public function refresh(): array
    {
        $manifest = $this->call('get', 'manifest');
        self::remember($manifest);

        return $manifest;
    }

    public function offerings(): array
    {
        return $this->call('get', 'offerings')['data'] ?? [];
    }

    public function students(int $offering): array
    {
        return $this->call('get', "offerings/{$offering}/students")['data'] ?? [];
    }

    /** @param  array<int, array{university_number: string, mark?: float|null, absent?: bool}>  $marks */
    public function sendMarks(int $offering, string $component, string $reference, array $marks): array
    {
        return $this->call('post', "offerings/{$offering}/marks", ['component' => $component, 'reference' => $reference, 'marks' => $marks]);
    }

    public function batch(int $id): array
    {
        return $this->call('get', "batches/{$id}");
    }

    private static function remember(array $manifest): void
    {
        $on = collect($manifest['tools'] ?? [])->filter(fn ($t) => $t['enabled'] ?? false)->pluck('key')->values()->all();
        Setting::setMany(['sis_tools' => json_encode($on), 'sis_university' => mb_substr((string) ($manifest['university'] ?? ''), 0, 200),
            'sis_last_ok' => now()->toIso8601String()]);
    }

    private function call(string $method, string $path, array $body = [], ?string $url = null, ?string $key = null): array
    {
        $url ??= self::url();
        $key ??= self::key();
        if ($url === '' || ! $key) {
            throw new RuntimeException('نظام الطلبة غير مربوط.');
        }
        try {
            $res = $this->http($key)->{$method}($url.'/'.$path, $body);
        } catch (ConnectionException) {
            throw $this->fail('تعذّر الوصول إلى نظام الطلبة على '.$url.'.');
        }
        if ($res->status() === 401) {
            throw $this->fail('رفض نظام الطلبة المفتاح، أو أن الربط أُلغي هناك.');
        }
        if ($res->status() === 403) {
            throw $this->fail('هذه الأداة متوقفة في نظام الطلبة («الموصلات»).');
        }
        if ($res->status() === 422) {
            $problems = (array) ($res->json('problems') ?? []);
            throw new RuntimeException(trim(($res->json('message') ?? 'رفض نظام الطلبة الطلب.').' '.implode('، ', array_slice($problems, 0, 5))));
        }
        if (! $res->successful() || ! is_array($res->json())) {
            throw $this->fail('ردّ نظام الطلبة بخطأ ('.$res->status().').');
        }

        return $res->json();
    }

    private function fail(string $message): RuntimeException
    {
        Setting::set('sis_error', now()->toIso8601String().' — '.$message);

        return new RuntimeException($message);
    }

    private function http(string $key): PendingRequest
    {
        return Http::withToken($key)->acceptJson()->asJson()->connectTimeout(5)->timeout(30)->withHeaders(['User-Agent' => 'Exams-SIS-Connector/1']);
    }
}
