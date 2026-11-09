<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// OSCE (2026-11-09, by request): when each candidate came into each station and when the assessor
// finished, for the candidate's route with the time at every station.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_osce_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_osce_station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['exam_osce_station_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_osce_visits');
    }
};
