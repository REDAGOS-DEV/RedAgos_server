<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drives have no booking limit and no planning estimate (project owner,
 * 2026-11-12): "Remove the booking capacity or maximum field", and replace the
 * staff's Expected participants with one live count, the pre-registered
 * donors. So `mobile_events.max_capacity` and `expected_participants` go.
 *
 * `donation_appointments.walk_in` marks a booking made at the drive itself for
 * someone who turns up unannounced. It puts them on the appointment path, so
 * their donation reaches the drive the way a booked donor's does
 * (appointment -> drive), without counting them as pre-registered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_events', function (Blueprint $table) {
            $table->dropColumn(['max_capacity', 'expected_participants']);
        });

        Schema::table('donation_appointments', function (Blueprint $table) {
            $table->boolean('walk_in')->default(false)->after('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('donation_appointments', function (Blueprint $table) {
            $table->dropColumn('walk_in');
        });

        Schema::table('mobile_events', function (Blueprint $table) {
            $table->unsignedInteger('max_capacity')->nullable()->after('end_time');
            $table->unsignedInteger('expected_participants')->nullable()->after('max_capacity');
        });
    }
};
