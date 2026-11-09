<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Practical (OSCE) exams: stations with a checklist each, an assessor at each station marking
// every candidate who rotates through; and adaptive exams (each next question harder or easier).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->boolean('is_osce')->default(false);
            $table->json('adaptive')->nullable();
        });
        Schema::create('exam_osce_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('name', 150);
            $table->unsignedSmallInteger('minutes')->default(8);
            $table->json('checklist');
            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('exam_osce_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_osce_station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('scores');
            $table->unsignedTinyInteger('global_rating')->nullable();
            $table->text('note')->nullable();
            $table->decimal('total', 8, 2)->default(0);
            $table->timestamps();
            $table->unique(['exam_osce_station_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_osce_marks');
        Schema::dropIfExists('exam_osce_stations');
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn(['is_osce', 'adaptive']));
    }
};
