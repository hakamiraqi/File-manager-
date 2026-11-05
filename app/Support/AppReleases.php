<?php

namespace App\Support;

/**
 * The app builds offered on /download, read from public/downloads/releases.json.
 * .github/workflows/mobile-apps.yml and offline-windows.yml upload the files next to it and
 * merge their entries in, e.g. {"android": {"version": "1.0.12", "url": "/downloads/FileNest-1.0.12.apk"}}.
 */
class AppReleases
{
    public const PLATFORMS = ['windows', 'android', 'ios', 'offline_server'];

    /** @return array<string, array{version: string, url: string, size?: int|null}> */
    public static function all(): array
    {
        $path = public_path('downloads/releases.json');
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            return [];
        }

        $out = [];
        foreach (self::PLATFORMS as $platform) {
            $entry = $data[$platform] ?? null;
            if (! is_array($entry) || empty($entry['url']) || empty($entry['version'])) {
                continue;
            }
            $file = public_path(ltrim(parse_url($entry['url'], PHP_URL_PATH) ?? '', '/'));
            $out[$platform] = [
                'version' => (string) $entry['version'],
                'url' => (string) $entry['url'],
                'size' => is_file($file) ? filesize($file) : ($entry['size'] ?? null),
            ];
        }

        return $out;
    }
}
