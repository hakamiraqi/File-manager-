<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Marking: results shown from a date, marking without names, rubric scores, and students' appeals.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->timestamp('results_at')->nullable();          // show_results = at_date
            $table->boolean('anonymous_marking')->default(false);
            $table->boolean('appeals_open')->default(false);
        });
        Schema::table('exam_answers', fn (Blueprint $table) => $table->json('rubric')->nullable());
        Schema::table('exam_marks', fn (Blueprint $table) => $table->json('rubric')->nullable());
        Schema::create('exam_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_question_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->string('status', 12)->default('open');   // open | accepted | rejected
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_appeals');
        Schema::table('exam_marks', fn (Blueprint $table) => $table->dropColumn('rubric'));
        Schema::table('exam_answers', fn (Blueprint $table) => $table->dropColumn('rubric'));
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn(['results_at', 'anonymous_marking', 'appeals_open']));
    }
};
