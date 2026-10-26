<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freeze the logo a Statement of Account was issued with.
     *
     * A revision reprints exactly as it was handed over, and the issuing
     * centre's logo heads it, so the file it printed with is recorded beside
     * its figures. A revision issued before this has none and prints with the
     * centre's current logo.
     *
     * A plain nullable column: adding it rebuilds nothing, so the immutability
     * triggers of create_billing_revisions_table stay in place on SQLite.
     */
    public function up(): void
    {
        Schema::table('billing_revisions', function (Blueprint $table): void {
            $table->string('issuer_logo_path', 255)->nullable()->after('payer_facility_id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_revisions', function (Blueprint $table): void {
            $table->dropColumn('issuer_logo_path');
        });
    }
};
