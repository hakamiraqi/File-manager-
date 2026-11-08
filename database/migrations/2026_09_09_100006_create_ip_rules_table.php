<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['blacklist', 'whitelist']);
            $table->string('ip_or_cidr', 45);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['type', 'ip_or_cidr']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_rules');
    }
};
