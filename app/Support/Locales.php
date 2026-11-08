<?php

namespace App\Support;

use App\Models\Language;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Interface languages: the `languages` table when it exists, otherwise
 * config('filenest.supported_locales'). Shape: code => [label, dir, flag, active].
 */
class Locales
{
    /** @return array<string, array{label: string, dir: string, flag: ?string, active: bool}> */
    public static function all(): array
    {
        $fromConfig = collect(config('filenest.supported_locales'))
            ->map(fn ($l, $code) => ['label' => $l['label'], 'dir' => $l['dir'] ?? 'ltr', 'flag' => $l['flag'] ?? null, 'active' => true])
            ->all();

        try {
            if (! Schema::hasTable('languages')) {
                return $fromConfig;
            }

            $rows = Cache::rememberForever('languages.all', fn () => Language::orderBy('sort')->orderBy('id')->get()
                ->mapWithKeys(fn (Language $l) => [$l->code => ['label' => $l->name, 'dir' => $l->dir, 'flag' => $l->flag, 'active' => $l->is_active]])
                ->all());
        } catch (\Throwable) {
            return $fromConfig;
        }

        return $rows ?: $fromConfig;
    }

    /** Languages users can pick. */
    public static function active(): array
    {
        return array_filter(self::all(), fn ($l) => $l['active']);
    }

    public static function isActive(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::active());
    }

    public static function dir(string $code): string
    {
        return self::all()[$code]['dir'] ?? 'ltr';
    }

    public static function flush(): void
    {
        Cache::forget('languages.all');
    }
}
