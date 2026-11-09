<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Exams the central server hands to the halls' servers, and a phone number for SMS/WhatsApp.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->boolean('hall_sync')->default(false);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn('hall_sync'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('phone'));
    }
};
