<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shelf books get covers by themselves too (2026-11-13): from the matching PDF, then online. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('cover_status', 16)->nullable()->index()->after('cover_path');
            $table->string('cover_source', 16)->nullable()->after('cover_status');
            $table->timestamp('cover_checked_at')->nullable()->after('cover_source');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['cover_status', 'cover_source', 'cover_checked_at']);
        });
    }
};
