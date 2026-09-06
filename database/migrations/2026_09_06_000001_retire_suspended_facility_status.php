<?php

use App\Enums\FacilityStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Return any suspended facility to service, now that the state is gone.
     *
     * Suspension was removed along with the rest of the facility lifecycle:
     * nothing sets it, nothing clears it, and FacilityStatus no longer has a
     * case for it. A row still carrying 'suspended' would therefore throw a
     * ValueError the moment Eloquent cast it, taking out every screen that
     * lists facilities — so the value has to be normalised rather than left to
     * rot.
     *
     * 'approved' is the honest target: a suspended facility was approved before
     * it was suspended, and with the state retired there is no longer any
     * concept under which it should be blocked.
     */
    public function up(): void
    {
        DB::table('facilities')
            ->where('status', 'suspended')
            ->update([
                'status' => FacilityStatus::Approved->value,
                // The reason was written by the suspension it belonged to.
                // Leaving it would show as a rejection note against an active
                // facility on the Facility Management page.
                'rejection_reason' => null,
            ]);
    }

    /**
     * Irreversible by design.
     *
     * Which facilities were suspended is not recorded anywhere else, so there
     * is nothing to restore from. The audit log keeps the history of the
     * decisions themselves.
     */
    public function down(): void {}
};
