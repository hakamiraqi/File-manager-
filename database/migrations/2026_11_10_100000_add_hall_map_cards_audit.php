<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The hall map (each computer's row and seat in its room), login cards with a QR code
// (a code per student, renewed with each printing), and the answers audit log.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_computers', function (Blueprint $table) {
            $table->unsignedSmallInteger('seat_row')->nullable();
            $table->unsignedSmallInteger('seat_col')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('card_code', 64)->nullable()->unique();
        });
        Schema::create('exam_answer_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->unsignedBigInteger('exam_question_id');
            $table->json('response')->nullable();
            $table->string('source', 20)->default('page');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['exam_attempt_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answer_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['card_code']);
            $table->dropColumn('card_code');
        });
        Schema::table('exam_computers', fn (Blueprint $table) => $table->dropColumn(['seat_row', 'seat_col']));
    }
};
