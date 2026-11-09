<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hall integrity: exam versions (A/B/C) on neighbouring seats, a watermark and copy guard on the
// questions page, and signing in with a student number for students without a working e-mail.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->unsignedTinyInteger('variants')->default(1);
            $table->boolean('protect_content')->default(true);
        });
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->unsignedTinyInteger('variant')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('student_number', 50)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn(['variants', 'protect_content']));
        Schema::table('exam_attempts', fn (Blueprint $table) => $table->dropColumn('variant'));
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['student_number']);
            $table->dropColumn('student_number');
        });
    }
};
