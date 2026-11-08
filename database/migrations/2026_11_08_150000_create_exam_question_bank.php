<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The question bank: questions kept in categories outside any exam (exam_id null), shared
 * with the other teachers or not. An exam can copy them in, or hold a «random» slot that
 * draws N of a category for each student when the attempt starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_bank_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('subject')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->foreignId('exam_id')->nullable()->change();
            $table->foreignId('bank_category_id')->nullable()->after('exam_id')->constrained('exam_bank_categories')->cascadeOnDelete();
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            // Questions drawn from the bank for this attempt: {questionId: points}.
            $table->json('drawn')->nullable()->after('question_order');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', fn (Blueprint $table) => $table->dropColumn('drawn'));
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_category_id');
        });
        Schema::dropIfExists('exam_bank_categories');
    }
};
