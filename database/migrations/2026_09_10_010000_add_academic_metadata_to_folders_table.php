<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free-form bibliographic fields shown/edited on a folder's own page —
     * useful for organizing a folder of academic theses/research papers or
     * scanned books, without turning them into rigid, required columns.
     */
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->string('researcher_name')->nullable()->after('is_public');
            $table->string('supervisor_name')->nullable()->after('researcher_name');
            $table->string('thesis_title')->nullable()->after('supervisor_name');
            $table->string('publisher')->nullable()->after('thesis_title');
            $table->string('digital_library')->nullable()->after('publisher');
            $table->string('publication_year', 20)->nullable()->after('digital_library');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn([
                'researcher_name',
                'supervisor_name',
                'thesis_title',
                'publisher',
                'digital_library',
                'publication_year',
            ]);
        });
    }
};
