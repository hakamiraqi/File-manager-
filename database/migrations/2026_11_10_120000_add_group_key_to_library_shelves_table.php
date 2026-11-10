<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «مجموعة» (2026-11-10, by request, as on the exam hall map): bookcases grouped together are
// chosen with one tap and moved, turned, copied or deleted together.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_shelves', function (Blueprint $table) {
            $table->string('group_key', 40)->nullable()->after('locked');
        });
    }

    public function down(): void
    {
        Schema::table('library_shelves', function (Blueprint $table) {
            $table->dropColumn('group_key');
        });
    }
};
