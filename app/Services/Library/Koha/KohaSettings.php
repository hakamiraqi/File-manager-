<?php

namespace App\Services\Library\Koha;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * «الربط مع Koha» (2026-11-14): Koha runs the lending, FileNest is the front the readers and
 * the desk use. The client secret is kept encrypted and never sent to a page.
 */
class KohaSettings
{
    public const DEFAULTS = [
        'koha_enabled' => '0',
        'koha_url' => '',                 // https://koha.example.edu.iq (the staff interface's address)
        'koha_client_id' => '',
        'koha_library' => '',             // Koha library (branch) code: where lending and returns take place
        'koha_match' => 'cardnumber',     // how a FileNest reader is found in Koha: cardnumber | email
        'koha_create_patrons' => '0',     // add a reader missing from Koha instead of refusing
        'koha_categories' => '{"student":"ST","teacher":"T","staff":"S"}',
        'koha_item_type' => 'BK',         // item type for copies FileNest sends to Koha
        'koha_push' => '1',               // books catalogued in FileNest are sent to Koha
    ];

    public static function get(string $key): string
    {
        return (string) (Setting::get($key) ?? self::DEFAULTS[$key] ?? '');
    }

    public static function enabled(): bool
    {
        return self::get('koha_enabled') === '1' && self::url() !== '' && self::get('koha_client_id') !== '' && self::secret() !== null;
    }

    public static function url(): string
    {
        return rtrim(self::get('koha_url'), '/');
    }

    public static function library(): string
    {
        return self::get('koha_library');
    }

    public static function push(): bool
    {
        return self::get('koha_push') === '1';
    }

    public static function secret(): ?string
    {
        $v = Setting::get('koha_client_secret');
        if (blank($v)) {
            return null;
        }
        try {
            return Crypt::decryptString((string) $v);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,string> FileNest reader type → Koha patron category */
    public static function categories(): array
    {
        $v = json_decode(self::get('koha_categories'), true);

        return is_array($v) ? array_map('strval', $v) : json_decode(self::DEFAULTS['koha_categories'], true);
    }

    /** What the last sync did, and what this Koha's API offers (filled by the connection test). */
    public static function state(string $key): array
    {
        $v = json_decode((string) Setting::get($key), true);

        return is_array($v) ? $v : [];
    }

    public static function setState(string $key, array $value): void
    {
        Setting::set($key, json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    /** Whether this Koha's API has a route (from the spec read by the connection test). */
    public static function can(string $route): bool
    {
        $caps = self::state('koha_caps');

        return ! isset($caps['routes']) || in_array($route, $caps['routes'], true);
    }

    /** The settings as a page sees them: no secret, only whether one is saved. */
    public static function forPage(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $k) {
            $out[$k] = self::get($k);
        }
        $out['koha_categories'] = self::categories();
        $out['has_secret'] = self::secret() !== null;
        $out['caps'] = self::state('koha_caps');
        $out['last_sync'] = self::state('koha_last_sync');

        return $out;
    }
}
