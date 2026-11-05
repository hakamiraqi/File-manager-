<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Library, step 2: readers (members) and loans. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('card_number', 64)->unique();
            $table->string('type', 20)->default('student');
            $table->string('stage', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('active')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('library_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('copy_id')->constrained('book_copies')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('library_members')->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at');
            $table->dateTime('due_at')->index();
            $table->dateTime('returned_at')->nullable()->index();
            $table->unsignedSmallInteger('renewals')->default(0);
            $table->unsignedInteger('fine_amount')->default(0);
            $table->dateTime('fine_paid_at')->nullable();
            $table->boolean('fine_waived')->default(false);
            $table->string('return_condition', 20)->nullable();
            $table->dateTime('last_reminder_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_loans');
        Schema::dropIfExists('library_members');
    }
};
