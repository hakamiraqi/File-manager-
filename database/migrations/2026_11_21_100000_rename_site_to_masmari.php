<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «مسماري» (2026-11-21, by request): the site is renamed from FileNest to مسماري (Masmari),
 * with the clay-tablet logo and the matching «clay» skin. A name or skin an admin chose
 * themselves is kept; only the old defaults are replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->swap('site_name', ['FileNest', null], 'مسماري');
        $this->swap('active_skin', ['indigo', null], 'clay');
    }

    public function down(): void
    {
        $this->swap('site_name', ['مسماري'], 'FileNest');
        $this->swap('active_skin', ['clay'], 'indigo');
    }

    /** Sets $key to $to when its current value is one of $from (null = not saved yet). */
    private function swap(string $key, array $from, string $to): void
    {
        $current = DB::table('settings')->where('key', $key)->value('value');
        if (! in_array($current, $from, true)) {
            return;
        }
        Setting::set($key, $to);
    }
};
