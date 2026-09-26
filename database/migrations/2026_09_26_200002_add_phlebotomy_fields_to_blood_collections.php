<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "For Phlebotomist Use Only" box of Section II of the DOH form.
 *
 * The form asks for four things the table had no room for: the blood bag
 * (single, double or triple), the segment number, and when the draw started
 * and ended. The fifth line, "Phlebotomist", is already `collected_by` — the
 * authenticated staff member, never a name from the request.
 *
 * SEGMENT NUMBERS ARE UNIQUE PER FACILITY. The segment is the number on the
 * tube the laboratory tests, and the one the donor is given on the CUE slip.
 * Two donations sharing one at the same centre would let a reactive result be
 * filed against the wrong donor. Numbering schemes differ between centres, so
 * the rule is per facility — which needs `facility_id` on this table, since a
 * unique index cannot reach through `donations`. It is copied from the
 * donation it belongs to and backfilled here for existing rows.
 *
 * Only a database constraint is race-proof: the form request gives the
 * friendly error and the service still catches the violation.
 *
 * NULLABLE AT THE DATABASE, REQUIRED AT THE REQUEST. Every collection recorded
 * before this migration has none of these, and a unique index admits any
 * number of NULLs on both Postgres and SQLite, so existing rows cannot collide.
 *
 * `blood_bag_type` is a string rather than a database enum on purpose. Bag
 * configurations beyond the three the form prints exist (quadruple,
 * top-and-bottom), and widening an enum on Postgres needs the per-driver
 * workaround in 2026_09_26_000002. The PHP enum is the vocabulary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blood_collections', function (Blueprint $table) {
            $table->foreignId('facility_id')->nullable()->after('donation_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('blood_bag_type', 20)->nullable()->after('collected_by');
            $table->string('segment_number', 50)->nullable()->after('blood_bag_type');
            $table->dateTime('started_at')->nullable()->after('segment_number');
            $table->dateTime('ended_at')->nullable()->after('started_at');

            $table->unique(['facility_id', 'segment_number'], 'blood_collections_facility_segment_unique');
        });

        // A correlated subquery rather than a join: UPDATE ... JOIN is not
        // portable across Postgres and SQLite, and this runs on both.
        DB::table('blood_collections')->update([
            'facility_id' => DB::raw(
                '(select donations.facility_id from donations where donations.id = blood_collections.donation_id)'
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('blood_collections', function (Blueprint $table) {
            $table->dropUnique('blood_collections_facility_segment_unique');
            $table->dropConstrainedForeignId('facility_id');
            $table->dropColumn(['blood_bag_type', 'segment_number', 'started_at', 'ended_at']);
        });
    }
};
