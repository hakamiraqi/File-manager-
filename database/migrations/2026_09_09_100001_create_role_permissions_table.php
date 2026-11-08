<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 20);
            $table->string('action', 30);
            $table->boolean('allowed')->default(false);
            $table->timestamps();

            $table->unique(['role', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
