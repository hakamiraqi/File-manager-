<?php

namespace App\Models;

use App\Support\Locales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'dir', 'flag', 'is_active', 'sort'])]
class Language extends Model
{
    /** Languages that ship with the app and cannot be deleted. */
    public const BUILTIN = ['ar', 'en'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Locales::flush());
        static::deleted(fn () => Locales::flush());
    }
}
