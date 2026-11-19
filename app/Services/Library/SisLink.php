<?php

namespace App\Services\Library;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * «ربط نظام الطلبة (SIS)» (2026-11-19): SIS connects to the library like a connector. FileNest issues a key;
 * the SIS admin pastes it in SIS → الموصلات → المكتبة. Only a hash of the key is stored. Revoking it, or switching
 * the link off, shuts the /api/sis/v1 door at once.
 */
class SisLink
{
    public static function enabled(): bool
    {
        return Setting::get('sis_link_enabled') === '1' && filled(Setting::get('sis_link_hash'));
    }

    public static function check(string $token): bool
    {
        return $token !== '' && self::enabled() && hash_equals((string) Setting::get('sis_link_hash'), hash('sha256', $token));
    }

    /** A new key; the old one stops working. Returned once, never stored in the clear. */
    public static function issue(): string
    {
        $plain = 'fnsis_'.Str::random(48);
        Setting::set('sis_link_hash', hash('sha256', $plain));
        Setting::set('sis_link_hint', substr($plain, -4));
        Setting::set('sis_link_enabled', '1');
        Setting::set('sis_link_issued_at', now()->toDateTimeString());

        return $plain;
    }

    public static function revoke(): void
    {
        Setting::set('sis_link_hash', '');
        Setting::set('sis_link_hint', '');
        Setting::set('sis_link_enabled', '0');
    }

    public static function touch(): void
    {
        $last = Setting::get('sis_link_last_seen');
        if (! $last || now()->diffInMinutes(\Illuminate\Support\Carbon::parse($last), true) >= 5) {
            Setting::set('sis_link_last_seen', now()->toDateTimeString());
        }
    }

    public static function forPage(): array
    {
        return [
            'enabled' => self::enabled(),
            'hint' => Setting::get('sis_link_hint') ?: null,
            'issued_at' => Setting::get('sis_link_issued_at'),
            'last_seen' => Setting::get('sis_link_last_seen'),
        ];
    }
}
