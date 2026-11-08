<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['locale', 'key', 'value'])]
class TranslationOverride extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (self $row) => Cache::forget("translations.{$row->locale}"));
        static::deleted(fn (self $row) => Cache::forget("translations.{$row->locale}"));
    }

    /**
     * @return array<string, string>
     */
    public static function forLocale(string $locale): array
    {
        return Cache::rememberForever("translations.{$locale}", fn () => self::where('locale', $locale)->pluck('value', 'key')->all());
    }
}
