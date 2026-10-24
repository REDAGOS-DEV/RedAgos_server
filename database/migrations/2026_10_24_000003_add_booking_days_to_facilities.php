<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The days a blood centre takes bookings, beside the hours it already has
 * (slots_start_at to slots_end_at): its open weekdays, and the dates it is
 * closed (holidays, events). Null open_days keeps every day open, as before.
 * Set from Settings by the centre's Admin and its Medical Receptionist
 * (BookingScheduleService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            // 0 = Monday ... 6 = Sunday.
            $table->json('open_days')->nullable()->after('slots_end_at');
            // [{date: 'Y-m-d', reason: string|null}]
            $table->json('closed_dates')->nullable()->after('open_days');
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn(['open_days', 'closed_dates']);
        });
    }
};
