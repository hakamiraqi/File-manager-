<?php

namespace App\Support;

use App\Models\RolePermission;
use App\Models\Setting;

/**
 * The visitors' site (2026-11-06, by request): a home page with the public files,
 * the library catalogue, theses and «تواصل معنا», each switched on or off by the admin.
 * guest_access_enabled is the master switch for visitors who are not signed in.
 */
class PublicSite
{
    public static function enabled(): bool
    {
        return filter_var(Setting::get('guest_access_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function files(): bool
    {
        return self::enabled() && self::on('public_files_enabled') && RolePermission::allows('guest', 'view');
    }

    public static function library(): bool
    {
        return self::enabled() && self::on('public_library_enabled');
    }

    public static function theses(): bool
    {
        return self::enabled() && self::on('public_theses_enabled');
    }

    /** The contact form is for visitors and signed-in users alike. */
    public static function contact(): bool
    {
        return self::on('contact_enabled');
    }

    /** Section switches are on unless the admin turned them off. */
    private static function on(string $key): bool
    {
        return filter_var(Setting::get($key, '1'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function sections(): array
    {
        return [
            'enabled' => self::enabled(),
            'files' => self::files(),
            'library' => self::library(),
            'theses' => self::theses(),
            'contact' => self::contact(),
        ];
    }
}
