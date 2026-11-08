<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// No going back (sequential exams), a calculator for the students, and tags and difficulty on
// questions (to draw random questions by them, and to report by topic).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('navigation', 12)->default('free');    // free | sequential
            $table->string('calculator', 12)->default('none');    // none | basic | scientific
        });
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->json('tags')->nullable();
            $table->unsignedTinyInteger('difficulty')->nullable();   // 1 easy … 3 hard
        });
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('position')->default(0);    // sequential: the furthest question reached
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', fn (Blueprint $table) => $table->dropColumn('position'));
        Schema::table('exam_questions', fn (Blueprint $table) => $table->dropColumn(['tags', 'difficulty']));
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn(['navigation', 'calculator']));
    }
};
