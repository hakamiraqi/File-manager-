<?php

namespace App\Services\Exams;

use App\Models\Setting;
use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A copy of each day's backup on storage away from this server (2026-11-09): any S3-compatible
 * bucket (Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO...). The database zip is encrypted
 * here with the admin's passphrase (AES-256-GCM) before it leaves, so the storage company cannot
 * read students' answers; `php artisan backup:offsite --decrypt=file` opens one again.
 *
 * Requests are signed by hand (AWS Signature Version 4), so no SDK is needed.
 */
class OffsiteBackup
{
    private const MAGIC = 'ENB1';

    public function __construct(private DatabaseBackupService $backups) {}

    public static function configured(): bool
    {
        return filled(Setting::get('offsite_url')) && filled(Setting::get('offsite_bucket')) && filled(Setting::get('offsite_key_id'))
            && filled(Setting::get('offsite_secret')) && filled(Setting::get('offsite_pass'));
    }

    /** @return array{url: string, bucket: string, region: string, key: string, secret: string, pass: string, prefix: string, keep: int} */
    public static function config(): array
    {
        $dec = fn ($k) => Setting::get($k) ? Crypt::decryptString((string) Setting::get($k)) : '';

        return [
            'url' => rtrim((string) Setting::get('offsite_url'), '/'),
            'bucket' => (string) Setting::get('offsite_bucket'),
            'region' => (string) (Setting::get('offsite_region') ?: 'auto'),
            'key' => (string) Setting::get('offsite_key_id'),
            'secret' => $dec('offsite_secret'),
            'pass' => $dec('offsite_pass'),
            'prefix' => trim((string) Setting::get('offsite_prefix', ''), '/'),
            'keep' => max(0, (int) (Setting::get('offsite_keep') ?? 30)),
        ];
    }

    /** Backs up now, sends the encrypted copy, and deletes copies older than `keep` days. */
    public function run(): array
    {
        try {
            $c = self::config();
            $made = $this->backups->create('offsite');
            $path = $this->backups->dir().DIRECTORY_SEPARATOR.$made['filename'];
            $name = ($c['prefix'] !== '' ? $c['prefix'].'/' : '').'examnest-'.now()->format('Ymd-His').'.zip.enc';
            $body = self::encrypt((string) file_get_contents($path), $c['pass']);
            $this->request('PUT', $name, $body)->throw();
            $pruned = $this->prune($c);
            Setting::setMany(['offsite_last_ok' => now()->toIso8601String(), 'offsite_last_error' => '', 'offsite_last_file' => $name,
                'offsite_last_size' => (string) strlen($body)]);

            return ['ok' => true, 'file' => $name, 'size' => strlen($body), 'pruned' => $pruned];
        } catch (\Throwable $e) {
            $msg = mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 300);
            Setting::set('offsite_last_error', now()->toIso8601String().' '.$msg);

            return ['ok' => false, 'error' => $msg];
        }
    }

    /** The copies on the storage: [[key, time], ...], oldest first. */
    public function list(): array
    {
        $c = self::config();
        $r = $this->request('GET', '', '', ['list-type' => '2', 'prefix' => ($c['prefix'] !== '' ? $c['prefix'].'/' : '').'examnest-'])->throw();
        $xml = @simplexml_load_string($r->body());
        $out = [];
        foreach ($xml?->Contents ?? [] as $o) {
            $out[] = ['key' => (string) $o->Key, 'time' => strtotime((string) $o->LastModified) ?: 0, 'size' => (int) $o->Size];
        }
        usort($out, fn ($a, $b) => $a['time'] <=> $b['time']);

        return $out;
    }

    private function prune(array $c): int
    {
        if ($c['keep'] === 0) {
            return 0;
        }
        $n = 0;
        $before = now()->subDays($c['keep'])->getTimestamp();
        foreach ($this->list() as $o) {
            if ($o['time'] && $o['time'] < $before && str_ends_with($o['key'], '.zip.enc')) {
                $this->request('DELETE', $o['key'])->successful() && $n++;
            }
        }

        return $n;
    }

    public static function encrypt(string $data, string $pass): string
    {
        if ($pass === '') {
            throw new RuntimeException('لا توجد كلمة سر لتشفير النسخ.');
        }
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($data, 'aes-256-gcm', self::key($pass, $salt), OPENSSL_RAW_DATA, $iv, $tag);

        return self::MAGIC.$salt.$iv.$tag.$cipher;
    }

    public static function decrypt(string $blob, string $pass): string
    {
        if (! str_starts_with($blob, self::MAGIC) || strlen($blob) < 48) {
            throw new RuntimeException('ليس ملف نسخة مشفّرة.');
        }
        $salt = substr($blob, 4, 16);
        $iv = substr($blob, 20, 12);
        $tag = substr($blob, 32, 16);
        $out = openssl_decrypt(substr($blob, 48), 'aes-256-gcm', self::key($pass, $salt), OPENSSL_RAW_DATA, $iv, $tag);
        if ($out === false) {
            throw new RuntimeException('كلمة السر خطأ أو الملف تالف.');
        }

        return $out;
    }

    private static function key(string $pass, string $salt): string
    {
        return hash_pbkdf2('sha256', $pass, $salt, 200000, 32, true);
    }

    /** A signed request to the bucket (path style: {url}/{bucket}/{key}). */
    public function request(string $method, string $key, string $body = '', array $query = [])
    {
        $c = self::config();
        $parts = parse_url($c['url']);
        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $base = rtrim($parts['path'] ?? '', '/');
        $path = $base.'/'.rawurlencode($c['bucket']).($key !== '' ? '/'.implode('/', array_map('rawurlencode', explode('/', $key))) : '/');
        ksort($query);
        $qs = implode('&', array_map(fn ($k, $v) => rawurlencode($k).'='.rawurlencode($v), array_keys($query), $query));
        $time = gmdate('Ymd\THis\Z');
        $date = substr($time, 0, 8);
        $hash = hash('sha256', $body);
        $headers = ['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $time];
        $canonical = implode("\n", [$method, $path, $qs,
            implode('', array_map(fn ($k, $v) => $k.':'.$v."\n", array_keys($headers), $headers)), implode(';', array_keys($headers)), $hash]);
        $scope = "{$date}/{$c['region']}/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n{$time}\n{$scope}\n".hash('sha256', $canonical);
        $k = hash_hmac('sha256', $date, 'AWS4'.$c['secret'], true);
        foreach ([$c['region'], 's3', 'aws4_request'] as $part) {
            $k = hash_hmac('sha256', $part, $k, true);
        }
        $auth = "AWS4-HMAC-SHA256 Credential={$c['key']}/{$scope}, SignedHeaders=".implode(';', array_keys($headers)).', Signature='.hash_hmac('sha256', $toSign, $k);
        $url = $parts['scheme'].'://'.$host.$path.($qs !== '' ? '?'.$qs : '');
        $http = Http::timeout(600)->withHeaders(['x-amz-content-sha256' => $hash, 'x-amz-date' => $time, 'Authorization' => $auth]);

        return match ($method) {
            'PUT' => $http->withBody($body, 'application/octet-stream')->put($url),
            'DELETE' => $http->delete($url),
            default => $http->get($url),
        };
    }
}
