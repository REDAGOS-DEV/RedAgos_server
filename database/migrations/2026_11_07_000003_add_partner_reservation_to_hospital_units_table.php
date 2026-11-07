<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A hospital bag may be reserved for a blood donor partner.
     *
     * Project owner, 2026-11-07: staff open a bag on Blood Bank Inventory and
     * reserve it for a blood donor partner, and the daily Inventory Report
     * prints it purple. The bag keeps its status: a reservation is a mark on a
     * bag on the shelf, not a step in its lifecycle, so it is three nullable
     * columns rather than another status.
     */
    public function up(): void
    {
        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->string('partner_name', 150)->nullable()->after('stocked_by');
            $table->timestamp('partner_reserved_at')->nullable()->after('partner_name');
        });

        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->foreignId('partner_reserved_by')->nullable()->after('partner_reserved_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->dropForeign(['partner_reserved_by']);
        });

        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->dropColumn(['partner_name', 'partner_reserved_at', 'partner_reserved_by']);
        });
    }
};
