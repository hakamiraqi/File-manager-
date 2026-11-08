<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «تواصل معنا» (2026-11-06, by request): messages and requests from visitors and users.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('inquiry');
            $table->string('name', 120);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('subject', 190);
            $table->text('message');
            // What the request is about (a book or a thesis), when sent from its page.
            $table->string('about_type', 20)->nullable();
            $table->unsignedBigInteger('about_id')->nullable();
            $table->string('status', 20)->default('new');
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
