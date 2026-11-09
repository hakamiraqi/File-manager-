<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The hall an exam is held in (for the department's timetable), several sittings of one exam
// in the same hall, and the files an open-book exam allows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('hall', 100)->nullable();
            $table->json('sessions')->nullable();
            $table->json('resources')->nullable();
        });
        Schema::table('exam_attendance', function (Blueprint $table) {
            $table->unsignedTinyInteger('session')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_attendance', fn (Blueprint $table) => $table->dropColumn('session'));
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn(['hall', 'sessions', 'resources']));
    }
};
