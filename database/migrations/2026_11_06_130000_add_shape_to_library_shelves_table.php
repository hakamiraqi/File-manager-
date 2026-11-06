<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «عوائق» (2026-11-06, by request): an obstacle keeps the outline it was drawn with (points in
// metres, relative to x/y before rotation) and its height.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_shelves', function (Blueprint $table) {
            $table->json('points')->nullable()->after('depth');
            $table->decimal('height', 4, 2)->nullable()->after('points');
        });
    }

    public function down(): void
    {
        Schema::table('library_shelves', function (Blueprint $table) {
            $table->dropColumn(['points', 'height']);
        });
    }
};
