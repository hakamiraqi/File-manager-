<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exam system: examiners write exams (questions of several kinds, texts in several
 * languages); students sit them within a time limit and are marked automatically, the
 * essays by hand. Per-student overrides give extra time or attempts; an event log keeps
 * what the browser reported during an attempt (left the tab, pasted, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A teacher: writes exams and marks them. Admins always can.
            $table->boolean('is_examiner')->default(false);
            // Extra time on every exam (1.5 = 50% more), for students with an accommodation.
            $table->decimal('exam_time_multiplier', 4, 2)->default(1)->after('is_examiner');
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('title');
            $table->json('description')->nullable();
            $table->string('subject')->nullable();
            $table->string('stage', 100)->nullable();
            $table->string('department')->nullable();
            // The languages the exam is written in, first = the default one; and its text direction.
            $table->json('languages');
            $table->string('direction', 4)->default('auto'); // auto | rtl | ltr
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(1);
            $table->string('grading_method', 10)->default('highest'); // highest | average | first | last
            $table->decimal('pass_mark', 5, 2)->nullable(); // percent
            $table->string('password')->nullable();
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_choices')->default(false);
            $table->string('show_results', 15)->default('after_submit'); // after_submit | after_close | never
            $table->boolean('show_answers')->default(true);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->index(['is_published', 'opens_at']);
        });

        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->json('body');
            $table->json('feedback')->nullable();
            $table->decimal('points', 6, 2)->default(1);
            // Per type: short {answers, case_sensitive}, numeric {answer, tolerance}, essay {min_words, max_words}.
            $table->json('settings')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('exam_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_question_id')->constrained()->cascadeOnDelete();
            $table->json('body');
            // Matching: the text this item goes with. Ordering: the order is `position`.
            $table->json('match')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('exam_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('extra_minutes')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->unsignedSmallInteger('max_attempts')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['exam_id', 'user_id']);
        });

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->default(0);
            $table->boolean('needs_grading')->default(false);
            $table->json('question_order')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['exam_id', 'user_id']);
        });

        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_question_id')->constrained()->cascadeOnDelete();
            $table->json('response')->nullable();
            $table->boolean('flagged')->default(false);
            $table->decimal('points', 6, 2)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['exam_attempt_id', 'exam_question_id']);
        });

        Schema::create('exam_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // hidden | blur | paste | fullscreen_exit | ip_changed
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_events');
        Schema::dropIfExists('exam_answers');
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('exam_overrides');
        Schema::dropIfExists('exam_choices');
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('exams');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_examiner', 'exam_time_multiplier']));
    }
};
