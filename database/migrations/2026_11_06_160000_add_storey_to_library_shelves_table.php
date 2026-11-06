<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «بناية بعدة طوابق» (2026-11-06, by request): which floor of the building each item is on
// (0 = the ground floor). Halls, corridors, doors and stairs are rows like the bookcases.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_shelves', function (Blueprint $table) {
            $table->unsignedTinyInteger('storey')->default(0)->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('library_shelves', function (Blueprint $table) {
            $table->dropColumn('storey');
        });
    }
};
