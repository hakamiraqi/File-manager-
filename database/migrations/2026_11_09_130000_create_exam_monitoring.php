<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The hall during an exam: attendance and seats, when each attempt was last heard from, and
// announcements the invigilator sends to every student in the exam.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('present')->default(true);
            $table->string('seat', 20)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['exam_id', 'user_id']);
        });
        Schema::create('exam_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('body', 500);
            $table->timestamps();
        });
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', fn (Blueprint $table) => $table->dropColumn('last_seen_at'));
        Schema::dropIfExists('exam_announcements');
        Schema::dropIfExists('exam_attendance');
    }
};
