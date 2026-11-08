<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Library, step 3: holidays, holds (reservations) and the notices readers see on the site. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('library_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('library_members')->cascadeOnDelete();
            $table->foreignId('copy_id')->nullable()->constrained('book_copies')->nullOnDelete();
            $table->string('status', 20)->default('waiting')->index();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('library_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('library_members')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('message', 500);
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_notices');
        Schema::dropIfExists('library_holds');
        Schema::dropIfExists('library_holidays');
    }
};
