<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_emails');
    }
};
