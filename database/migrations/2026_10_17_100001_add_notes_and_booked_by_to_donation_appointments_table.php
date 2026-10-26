<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appointments staff book for a donor who phoned in (project owner, 2026-10-17).
 *
 * `booked_by` is the staff member who took the call; null for a booking the
 * donor made in the portal. `notes` is what they wrote down at the time, for
 * the desk on the day. Neither changes how the appointment is counted or
 * checked in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_appointments', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('status');
            $table->foreignId('booked_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('donation_appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booked_by');
            $table->dropColumn('notes');
        });
    }
};
