<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the columns inventory needs on top of the initial blood_units shape.
     *
     * expired_at and discarded_at exist so the two facts survive each other.
     * status stays the single truth about what a unit is now; these record when
     * it became each thing. Without them, discarding an expired unit erases the
     * expiry event — the row still carries expiry_date, but nothing separates
     * "expired on the shelf, then disposed of" from "disposed of while still in
     * date because the seal broke", and those are different numbers in an
     * inventory-movement report.
     *
     * They are timestamps rather than a status-history table because there is
     * exactly one of each event per unit. A history table would be the right
     * answer for reserve/release, which cycles; that belongs to allocation.
     */
    public function up(): void
    {
        Schema::table('blood_units', function (Blueprint $table) {
            $table->string('storage_location', 100)->nullable()->after('blood_type_id');
            $table->string('discard_reason', 255)->nullable()->after('status');
            $table->timestamp('expired_at')->nullable()->after('discard_reason');
            $table->timestamp('discarded_at')->nullable()->after('expired_at');

            $table->index(['facility_id', 'storage_location']);

            // Not used by this module's endpoints. They are what makes "how many
            // units expired last month" cheap when reporting arrives.
            $table->index(['facility_id', 'expired_at']);
            $table->index(['facility_id', 'discarded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('blood_units', function (Blueprint $table) {
            $table->dropIndex(['facility_id', 'storage_location']);
            $table->dropIndex(['facility_id', 'expired_at']);
            $table->dropIndex(['facility_id', 'discarded_at']);

            $table->dropColumn(['storage_location', 'discard_reason', 'expired_at', 'discarded_at']);
        });
    }
};
