<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «قفل» (2026-11-07, by request): a locked hall, bookcase or wall can't be moved or resized by
// mistake in the map editor until it is unlocked.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_shelves', function (Blueprint $table) {
            $table->boolean('locked')->default(false)->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('library_shelves', function (Blueprint $table) {
            $table->dropColumn('locked');
        });
    }
};
