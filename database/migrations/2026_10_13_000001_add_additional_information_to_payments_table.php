<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a card payment carry additional information: the cardholder's last name.
     *
     * Asked for by the project owner on 2026-10-13. Keyed in at the counter
     * beside the card terminal's reference, so billing staff can match the
     * payment to the slip. Card payments only; null on every other method and
     * on card payments recorded before it.
     *
     * A nullable column is added in place on SQLite, without rebuilding the
     * table, so the ledger triggers stay as they are.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('additional_information', 100)->nullable()->after('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('additional_information');
        });
    }
};
