<?php

namespace App\Services\Exams;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * SMS or WhatsApp messages through the institution's own gateway (hosted site only). Any HTTP
 * gateway works: its address (and, for POST, its body) carry {phone} and {message}, and an
 * optional header carries the gateway's key.
 */
class Messenger
{
    public static function enabled(): bool
    {
        return ! config('offline.edition') && filled(Setting::get('msg_url'));
    }

    /** Sends to many: [phone => text]. Returns how many the gateway accepted. */
    public function send(array $messages): array
    {
        $url = (string) Setting::get('msg_url');
        $method = Setting::get('msg_method') === 'GET' ? 'GET' : 'POST';
        $body = (string) Setting::get('msg_body');
        $header = Setting::get('msg_header') ? Crypt::decryptString((string) Setting::get('msg_header')) : null;
        $ok = 0;
        $failed = [];
        foreach ($messages as $phone => $text) {
            $json = fn ($v) => substr(json_encode((string) $v, JSON_UNESCAPED_UNICODE), 1, -1);
            $fill = fn (string $tpl, bool $encode) => strtr($tpl, $encode ? ['{phone}' => rawurlencode($phone), '{message}' => rawurlencode($text)]
                : (str_starts_with(ltrim($tpl), '{') ? ['{phone}' => $json($phone), '{message}' => $json($text)] : ['{phone}' => rawurlencode($phone), '{message}' => rawurlencode($text)]));
            $req = Http::timeout(20);
            if ($header && str_contains($header, ':')) {
                [$name, $value] = array_map('trim', explode(':', $header, 2));
                $req = $req->withHeaders([$name => $value]);
            }
            try {
                $res = $method === 'GET' ? $req->get($fill($url, true))
                    : ($body !== '' ? $req->withBody($fill($body, false), str_starts_with(ltrim($body), '{') ? 'application/json' : 'application/x-www-form-urlencoded')->post($fill($url, true))
                        : $req->asForm()->post($fill($url, true), ['to' => $phone, 'message' => $text]));
                $res->successful() ? $ok++ : $failed[] = $phone;
            } catch (\Throwable) {
                $failed[] = $phone;
            }
        }

        return ['sent' => $ok, 'failed' => $failed];
    }

    /** A phone number as the gateways want it: digits, with the country code (Iraq by default). */
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', strtr((string) $phone, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if ($digits === '' || strlen($digits) < 8) {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = (Setting::get('msg_country') ?: '964').substr($digits, 1);
        }

        return $digits;
    }
}
