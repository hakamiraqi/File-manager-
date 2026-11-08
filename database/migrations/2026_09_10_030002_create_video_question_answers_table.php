<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (question, user) — a user's answer is final once
     * submitted, enforced here rather than in application code, so a
     * retake requires a deliberate reset rather than a silent overwrite.
     */
    public function up(): void
    {
        Schema::create('video_question_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_question_choice_id')->constrained('video_question_choices')->cascadeOnDelete();
            $table->boolean('is_correct');
            $table->timestamps();

            $table->unique(['video_question_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_question_answers');
    }
};
