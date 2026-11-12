<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const CACHE_KEY = 'filenest.settings';

    public static function all_settings(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return static::query()->pluck('value', 'key')->all();
        });
    }

    /** Settings that never leave the server (encrypted keys); only whether they are set is shown. */
    public const SECRET = ['ai_api_key', 'covers_google_key'];

    /** Every setting except the secret ones, for admin pages and the admin API. */
    public static function visibleSettings(): array
    {
        $all = static::all_settings();
        foreach (self::SECRET as $k) {
            $all[$k.'_set'] = filled($all[$k] ?? null);
            unset($all[$k]);
        }

        return $all;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all_settings()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Cache::forget(self::CACHE_KEY);
    }
}
