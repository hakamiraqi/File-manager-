<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Double marking: hand-marked answers get two independent marks; close marks are averaged,
// far-apart ones wait for the exam's owner to settle them. Markers other than the owner are
// invited per exam.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->boolean('double_marking')->default(false)->after('seb_keys');
            $table->unsignedTinyInteger('marking_tolerance')->default(20)->after('double_marking');   // % of the question's points
        });
        Schema::create('exam_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['exam_id', 'user_id']);
        });
        Schema::create('exam_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_answer_id')->constrained('exam_answers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('points', 8, 2);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['exam_answer_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_marks');
        Schema::dropIfExists('exam_markers');
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn(['double_marking', 'marking_tolerance']));
    }
};
