<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            // Marks a top-level folder (and everything under it) as part of
            // the guest-accessible public space, when guest access is enabled.
            $table->boolean('is_public')->default(false)->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
