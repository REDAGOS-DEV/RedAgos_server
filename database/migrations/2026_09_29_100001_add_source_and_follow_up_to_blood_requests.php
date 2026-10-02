<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record where a request was keyed in, and link a remainder to the request it came from.
     *
     * A watcher sometimes carries a patient's request straight to a blood
     * centre instead of the hospital blood bank. The centre phones the hospital
     * and, once the hospital confirms, records the request on its behalf. That
     * is still a Patient Transfusion request from that hospital — the purpose
     * does not change, only where it was entered — so the source is its own
     * column rather than a third purpose.
     *
     * requested_by becomes nullable because a walk-in has no hospital user who
     * submitted it. recorded_by names the centre staff member who did.
     *
     * parent_request_id links a follow-up to the request whose remaining
     * quantity it carries to another facility. restrictOnDelete, matching the
     * facility FKs: a request with follow-ups is part of a patient's history and
     * is never deleted out from under them.
     *
     * closed_at is written only by RequestStatusResolver, once every line is
     * resolved. A partially fulfilled request whose remainder was closed or
     * forwarded keeps the `partial` status but can no longer be allocated.
     *
     * Values hard-coded rather than read from RequestSource, so a later change
     * to the enum cannot change what this migration did.
     */
    public function up(): void
    {
        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->enum('request_source', ['blood_bank_portal', 'blood_center_walk_in'])
                ->default('blood_bank_portal')
                ->after('request_purpose');

            $table->foreignId('recorded_by')->nullable()->after('requested_by')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->foreignId('parent_request_id')->nullable()->after('reference_number')
                ->constrained('blood_requests')->cascadeOnUpdate()->restrictOnDelete();

            $table->timestamp('closed_at')->nullable()->after('fulfilled_at');

            $table->index(['target_facility_id', 'request_source', 'status']);

            // The walk-in duplicate lookup: one hospital's requests for a named
            // patient.
            $table->index(['facility_id', 'patient_surname', 'patient_first_name']);
        });

        // A separate call, so the column change is not folded into the table
        // rebuild SQLite performs for the foreign keys above.
        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
        });
    }

    /**
     * Refuse to narrow requested_by back while walk-ins exist.
     *
     * A walk-in has no hospital submitter, and inventing one would put a name
     * on the record of someone who never asked for the blood.
     */
    public function down(): void
    {
        $walkIns = DB::table('blood_requests')->whereNull('requested_by')->count();

        if ($walkIns > 0) {
            throw new RuntimeException(
                "blood_requests holds {$walkIns} request(s) with no hospital submitter. "
                .'Remove the walk-in requests before rolling this back.'
            );
        }

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('requested_by')->nullable(false)->change();
        });

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->dropIndex(['facility_id', 'patient_surname', 'patient_first_name']);
            $table->dropIndex(['target_facility_id', 'request_source', 'status']);

            $table->dropConstrainedForeignId('parent_request_id');
            $table->dropConstrainedForeignId('recorded_by');

            $table->dropColumn(['request_source', 'closed_at']);
        });
    }
};
