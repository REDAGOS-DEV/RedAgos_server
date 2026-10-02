<?php

use App\Enums\BloodRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give a blood request the facility it was addressed to, and the trail of
     * who raised it and who decided it.
     *
     * The original table carries one facility FK, which names the requesting
     * facility only. That is not enough to express the workflow the paper
     * describes: a request is submitted *to* a chosen blood service facility,
     * and without target_facility_id there is no column that says which one, so
     * no facility can be shown its own incoming queue.
     *
     * target_facility_id is added NOT NULL because there is no sensible default
     * — a request addressed to nobody is not a request. That is only safe on an
     * empty table, which this one is in every environment: nothing has ever
     * written to it. The guard below refuses rather than assumes.
     */
    public function up(): void
    {
        $this->guardAgainstExistingRows();

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->string('reference_number', 30)->unique()->after('id');

            // restrictOnDelete, matching facility_id: a facility with requests
            // against it is not deletable, so the trail cannot be orphaned.
            $table->foreignId('target_facility_id')->after('facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            // Who raised it. Non-null and restricted for the same reason
            // billings.billed_by is: this is an accountability record.
            $table->foreignId('requested_by')->after('target_facility_id')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('rejection_reason', 255)->nullable()->after('status');

            // Nullable and nullOnDelete, matching facilities.approved_by: the
            // decision has not been taken yet on a pending request.
            $table->foreignId('reviewed_by')->nullable()->after('rejection_reason')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->timestamp('fulfilled_at')->nullable()->after('request_date');

            // The incoming queue reads exactly this: one facility's requests in
            // a given state, emergencies first.
            $table->index(['target_facility_id', 'status']);
            $table->index(['target_facility_id', 'urgency_level', 'request_date']);
        });
    }

    public function down(): void
    {
        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->dropIndex(['target_facility_id', 'urgency_level', 'request_date']);
            $table->dropIndex(['target_facility_id', 'status']);

            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('target_facility_id');

            $table->dropColumn(['reference_number', 'rejection_reason', 'reviewed_at', 'fulfilled_at']);
        });
    }

    /**
     * Refuse to run against rows that cannot be given a target facility.
     *
     * A NOT NULL column added to a populated table either fails outright or
     * needs a backfill value, and there is no honest one to invent here: no
     * existing row records which facility it was meant for. Failing with an
     * explanation beats guessing at which blood centre a live request was for.
     */
    private function guardAgainstExistingRows(): void
    {
        $existing = DB::table('blood_requests')->count();

        if ($existing > 0) {
            throw new RuntimeException(
                "blood_requests already holds {$existing} row(s). target_facility_id cannot be "
                .'backfilled automatically because no existing column records the facility a '
                .'request was addressed to. Resolve these rows manually before migrating.'
            );
        }
    }

    /**
     * The status column is untouched on purpose.
     *
     * BloodRequestStatus declares exactly the values the column already holds,
     * so there is nothing to sync. Referenced here so the enum and the table
     * stay visibly tied together for the next reader.
     *
     * @return array<int, string>
     */
    public static function declaredStatuses(): array
    {
        return BloodRequestStatus::values();
    }
};
