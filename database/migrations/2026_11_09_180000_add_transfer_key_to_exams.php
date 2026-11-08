<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The same exam on several servers (a hall's offline server, the central one): its packages and
// results bundles carry this key, so results come back to the right exam.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('transfer_key', 40)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn('transfer_key'));
    }
};
