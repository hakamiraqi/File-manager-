<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «الربط مع Koha» (2026-11-14): when Koha runs the circulation, each local record remembers
 * the Koha record it mirrors, so a sync updates it in place instead of adding a twin.
 */
return new class extends Migration
{
    private const TABLES = [
        'books' => 'koha_biblio_id',
        'book_copies' => 'koha_item_id',
        'library_members' => 'koha_patron_id',
        'library_loans' => 'koha_checkout_id',
        'library_holds' => 'koha_hold_id',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $column) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->unsignedBigInteger($column)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $column) {
            Schema::table($table, function (Blueprint $t) use ($table, $column) {
                $t->dropUnique("{$table}_{$column}_unique");
                $t->dropColumn($column);
            });
        }
    }
};
