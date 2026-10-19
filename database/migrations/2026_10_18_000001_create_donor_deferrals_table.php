<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferrals staff record by hand, outside any visit's screening (project
 * owner, 2026-10-18: the Deferrals page under Donor).
 *
 * A third source beside the screening outcome and the laboratory's
 * counselling referral, read through DonorDeferralRepository like the other
 * two. It is a table, not derived, because these can be lifted: the decision
 * log said a deferrals table becomes worth it exactly then.
 *
 *  - `facility_id`: the centre that recorded it. Only that centre lists its
 *    reason or lifts it; every centre sees the standing deferral.
 *  - `outcome`: a ScreeningOutcome deferral value, never `accepted`.
 *  - `ends_on`: the last day of a temporary deferral; null otherwise.
 *  - `lifted_at`, `lifted_by`, `lift_reason`: lifted rather than deleted, so
 *    the donor's history keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_deferrals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('donor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();

            $table->string('outcome', 32);
            $table->text('reason');
            $table->date('deferred_on');
            $table->date('ends_on')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lift_reason')->nullable();

            $table->timestamps();

            $table->index(['donor_id', 'lifted_at']);
            $table->index(['facility_id', 'deferred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_deferrals');
    }
};
