<?php

namespace App\Services\Library\Koha;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Koha's REST API (/api/v1), signed in with an API key (OAuth2 client credentials).
 * The key is made in Koha: a staff patron with the needed permissions → «Manage API keys».
 */
class KohaClient
{
    public const PER_PAGE = 500;

    /** Seconds to wait for Koha; page loads lower it so a slow Koha never holds a page up. */
    public static int $timeout = 30;

    public function base(): string
    {
        return KohaSettings::url().'/api/v1';
    }

    public function token(bool $fresh = false): string
    {
        $key = 'koha.token.'.md5(KohaSettings::url().'|'.KohaSettings::get('koha_client_id'));
        if ($fresh) {
            Cache::forget($key);
        }
        if ($t = Cache::get($key)) {
            return $t;
        }

        try {
            $r = Http::asForm()->acceptJson()->connectTimeout(min(10, self::$timeout))->timeout(min(20, self::$timeout))->post($this->base().'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => KohaSettings::get('koha_client_id'),
                'client_secret' => KohaSettings::secret(),
            ]);
        } catch (ConnectionException $e) {
            throw new KohaError('تعذّر الاتصال بخادم Koha: '.$e->getMessage());
        }
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new KohaError('رفض Koha مفتاح الربط (Client ID / Secret). تأكد منه ومن تفعيل RESTOAuth2ClientCredentials.', $r->status(), (array) $r->json());
        }
        Cache::put($key, $r->json('access_token'), max(60, (int) $r->json('expires_in', 3600) - 60));

        return $r->json('access_token');
    }

    private function http(bool $fresh = false): PendingRequest
    {
        $http = KohaSettings::basic()
            ? Http::withBasicAuth(KohaSettings::get('koha_username'), (string) KohaSettings::password())
            : Http::withToken($this->token($fresh));

        return $http->acceptJson()->connectTimeout(min(10, self::$timeout))->timeout(self::$timeout);
    }

    /** Sends a request; a stale token is renewed once. Koha's refusals become a KohaError. */
    public function send(string $method, string $path, array $options = [], array $headers = []): Response
    {
        $do = function (bool $fresh) use ($method, $path, $options, $headers) {
            try {
                $req = $this->http($fresh)->withHeaders($headers);
                if (isset($options['raw'])) {
                    $req = $req->withBody($options['raw'][0], $options['raw'][1]);
                    unset($options['raw']);
                }

                return $req->send($method, $this->base().$path, $options);
            } catch (ConnectionException $e) {
                throw new KohaError('تعذّر الاتصال بخادم Koha: '.$e->getMessage());
            }
        };
        $r = $do(false);
        if ($r->status() === 401 && ! KohaSettings::basic()) {
            $r = $do(true);
        }
        if ($r->status() === 401) {
            throw new KohaError(KohaSettings::basic()
                ? 'رفض Koha اسم المستخدم أو كلمة المرور، أو أن RESTBasicAuth غير مفعّل في Koha.'
                : 'رفض Koha مفتاح الربط.', 401, (array) $r->json());
        }
        if ($r->failed()) {
            $data = (array) $r->json();
            $msg = $data['error'] ?? ($data['errors'][0]['message'] ?? null) ?? "Koha: HTTP {$r->status()}";
            throw new KohaError((string) $msg, $r->status(), $data);
        }

        return $r;
    }

    public function get(string $path, array $query = []): array
    {
        return (array) $this->send('GET', $path, ['query' => $query])->json();
    }

    public function post(string $path, array $body = [], array $query = []): array
    {
        return (array) $this->send('POST', $path.($query ? '?'.http_build_query($query) : ''), ['json' => (object) $body])->json();
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $path);
    }

    /** A date from Koha (with its UTC offset) in the app's time zone, as it is stored. */
    public static function date(?string $v): ?Carbon
    {
        return $v ? Carbon::parse($v)->setTimezone(config('app.timezone')) : null;
    }

    /** One filter in Koha's query language: exact match on each key. */
    public static function q(array $where): string
    {
        return json_encode($where, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Every page of a list. @return \Generator<array> */
    public function each(string $path, array $query = [], array $headers = []): \Generator
    {
        for ($page = 1; ; $page++) {
            $rows = (array) $this->send('GET', $path, ['query' => $query + ['_page' => $page, '_per_page' => self::PER_PAGE]], $headers)->json();
            foreach ($rows as $row) {
                yield $row;
            }
            if (count($rows) < self::PER_PAGE) {
                return;
            }
        }
    }

    /** A record (or a page of records) as MARCXML. */
    public function marc(string $path, array $query = []): string
    {
        return $this->send('GET', $path, ['query' => $query], ['Accept' => 'application/marcxml+xml'])->body();
    }

    /** Adds (POST) or replaces (PUT) a bibliographic record sent as MARCXML. */
    public function sendMarc(string $method, string $path, string $xml, string $schema = 'MARC21'): array
    {
        return (array) $this->send($method, $path, ['raw' => [$xml, 'application/marcxml+xml']], [
            'x-record-schema' => $schema,
            'x-confirm-not-duplicate' => '1',
        ])->json();
    }
}
