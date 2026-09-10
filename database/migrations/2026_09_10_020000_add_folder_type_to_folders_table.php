<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a folder be marked as an ordinary file folder or a thesis
     * folder, so the UI knows whether to surface the bibliographic fields
     * added in the previous migration (researcher, supervisor, etc.) —
     * defaults to 'files' so every existing folder stays a plain folder.
     */
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->string('folder_type', 20)->default('files')->after('publication_year');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('folder_type');
        });
    }
};
