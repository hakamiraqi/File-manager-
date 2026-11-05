<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Interface languages managed from محرّر الترجمات (2026-11-05, by request): add a language,
// choose its flag or icon, direction, and switch it on or off.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 60);
            $table->string('dir', 3)->default('ltr');
            // A country code (flag), or "icon:<name>" for a plain icon.
            $table->string('flag', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        DB::table('languages')->insert([
            ['code' => 'ar', 'name' => 'العربية', 'dir' => 'rtl', 'flag' => 'iq', 'is_active' => true, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'en', 'name' => 'English', 'dir' => 'ltr', 'flag' => 'gb', 'is_active' => true, 'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
