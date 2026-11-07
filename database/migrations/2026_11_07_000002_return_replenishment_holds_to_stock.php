<?php

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\RequestPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A replenishment order no longer holds stock (project owner, 2026-11-07): its
 * units are picked when it is dispatched. Bags held for an order that has not
 * gone out go back on the shelf, and an order holding nothing else waits again
 * as confirmed, for its units to be picked at dispatch.
 *
 * Nothing to undo: nobody can say which bags ought to be held again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $holds = DB::table('request_allocations')
                ->join('blood_requests', 'blood_requests.id', '=', 'request_allocations.request_id')
                ->where('blood_requests.request_purpose', RequestPurpose::Replenishment->value)
                ->where('request_allocations.status', AllocationStatus::Allocated->value)
                ->select('request_allocations.id', 'request_allocations.unit_id', 'request_allocations.request_id')
                ->get();

            if ($holds->isEmpty()) {
                return;
            }

            DB::table('blood_units')
                ->whereIn('id', $holds->pluck('unit_id'))
                ->where('status', BloodUnitStatus::Reserved->value)
                ->update(['status' => BloodUnitStatus::Available->value]);

            DB::table('request_allocations')
                ->whereIn('id', $holds->pluck('id'))
                ->update([
                    'status' => AllocationStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Replenishment orders hold no stock; units are picked at dispatch.',
                ]);

            // Partly sent orders keep their status: what went out still stands.
            DB::table('blood_requests')
                ->whereIn('id', $holds->pluck('request_id')->unique())
                ->where('status', BloodRequestStatus::Processing->value)
                ->update(['status' => BloodRequestStatus::Pending->value]);
        });
    }

    public function down(): void
    {
        //
    }
};
