<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Running the hall: an attempt locks when the student leaves it and opens again with the
// invigilator's code; help requests; the hall's computers and who sits at which.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->boolean('resume_code_required')->default(false);
            $table->boolean('bind_computers')->default(false);
        });
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable();
            $table->string('lock_reason', 20)->nullable();
            $table->string('resume_code', 10)->nullable();
            $table->string('session_key', 64)->nullable();
            $table->timestamp('help_requested_at')->nullable();
        });
        Schema::create('exam_computers', function (Blueprint $table) {
            $table->id();
            $table->string('room', 100)->nullable();
            $table->string('label', 50);
            $table->string('ip', 45)->unique();
            $table->timestamps();
        });
        Schema::table('exam_attendance', function (Blueprint $table) {
            $table->foreignId('computer_id')->nullable()->constrained('exam_computers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_attendance', fn (Blueprint $table) => $table->dropConstrainedForeignId('computer_id'));
        Schema::dropIfExists('exam_computers');
        Schema::table('exam_attempts', fn (Blueprint $table) => $table->dropColumn(['locked_at', 'lock_reason', 'resume_code', 'session_key', 'help_requested_at']));
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn(['resume_code_required', 'bind_computers']));
    }
};
