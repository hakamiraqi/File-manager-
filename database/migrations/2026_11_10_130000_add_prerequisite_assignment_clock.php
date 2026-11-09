<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A practice exam a student must finish before this one, assignments (no timer, a deadline),
// and how far each hall computer's clock is from the server's.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->foreignId('requires_exam_id')->nullable()->constrained('exams')->nullOnDelete();
            $table->boolean('is_assignment')->default(false);
        });
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->integer('clock_offset')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', fn (Blueprint $table) => $table->dropColumn('clock_offset'));
        Schema::table('exams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requires_exam_id');
            $table->dropColumn('is_assignment');
        });
    }
};
