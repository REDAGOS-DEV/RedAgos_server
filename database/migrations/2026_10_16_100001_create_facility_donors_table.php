<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One blood centre's own record of a donor.
 *
 * Donors are global — `donor_profiles` carries no facility — but whether a
 * donor is still active with a centre, and what its staff noted about them,
 * belongs to that centre alone. "Detailed records stay with the facility that
 * created them", so these live beside the donor rather than on the profile,
 * where every other centre would read them.
 *
 * A row is also a relationship in its own right: a walk-in registered here is
 * this centre's donor from the moment of registration, not from their first
 * visit, which is what puts them on the Donors list straight away.
 *
 *  - `registered_here`: this centre created the account at its counter. Only
 *    then may its staff correct the donor's email or phone, and only while the
 *    donor has not taken the account over themselves.
 *  - `eligibility_reminded_at`: when this centre last emailed the donor that
 *    they may give again, so a second click does not mail them twice in a week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_donors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('donor_id')->constrained('users')->cascadeOnDelete();

            // active | inactive (App\Enums\FacilityDonorStatus). A donor with no
            // row is active: the status is opt-out, never something to backfill.
            $table->string('status', 16)->default('active');

            $table->text('notes')->nullable();

            $table->boolean('registered_here')->default(false);

            $table->timestamp('eligibility_reminded_at')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['facility_id', 'donor_id']);
            $table->index(['facility_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_donors');
    }
};
