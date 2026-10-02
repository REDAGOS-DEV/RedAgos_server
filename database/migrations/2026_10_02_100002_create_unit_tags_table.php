<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One patient's hold on one bag in a hospital blood bank, from tag to its end.
     *
     * A row is written when staff tag a bag to a patient and is carried through
     * crossmatch and transfusion — or into Untagged Assigned / Untagged
     * Crossmatched when a 24-hour period runs out or staff release it. It is
     * never deleted: a tag that ended is the history of where the bag nearly
     * went, and re-tagging the same bag writes a new row beside it.
     *
     * The patient is copied onto the row, as on transfusion_requests, because
     * RedAgos has no patient table and a patient served from the hospital's own
     * shelf has no requirement to point at. transfusion_request_id is the
     * optional link for one who does.
     *
     * A partial unique index over hospital_unit_id, restricted to the two
     * active statuses, is what stops one bag being held for two patients at
     * once. It is the backstop behind the row lock in HospitalInventoryService,
     * on the drivers that index partially (PostgreSQL in production, SQLite in
     * tests); see relax_request_allocation_unit_uniqueness for the reasoning.
     *
     * Every foreign key is declared inside Schema::create, so SQLite never
     * rebuilds this table. A later migration that adds a foreign key or changes
     * a column here WILL rebuild it on SQLite and silently turn the partial
     * index into a plain one — drop and recreate it around the change, as
     * add_request_item_id_to_request_allocations_table does.
     *
     * Values hard-coded rather than read from the enums, so a later change to
     * an enum cannot change what this migration did.
     */
    public function up(): void
    {
        Schema::create('unit_tags', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('hospital_unit_id')
                ->constrained('hospital_units')->cascadeOnUpdate()->restrictOnDelete();

            // The hospital, repeated from the unit so a facility's tag history
            // is one indexed read rather than a join.
            $table->foreignId('facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            $table->foreignId('transfusion_request_id')->nullable()
                ->constrained('transfusion_requests')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('patient_surname', 100);
            $table->string('patient_first_name', 100);
            $table->string('patient_middle_name', 100)->nullable();
            $table->unsignedSmallInteger('patient_age');
            $table->enum('patient_sex', ['male', 'female']);
            $table->string('patient_record_number', 60)->nullable();
            $table->string('patient_ward', 100)->nullable();
            $table->string('attending_physician', 150)->nullable();
            $table->foreignId('patient_blood_type_id')->nullable()
                ->constrained('blood_types')->cascadeOnUpdate()->restrictOnDelete();

            $table->enum('status', [
                'tag_assigned',
                'tag_crossmatched',
                'transfused',
                'untagged_assigned',
                'untagged_crossmatched',
            ])->default('tag_assigned');

            $table->timestamp('tagged_at');
            $table->foreignId('tagged_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('crossmatch_deadline_at');

            $table->timestamp('crossmatched_at')->nullable();
            $table->foreignId('crossmatched_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('transfusion_deadline_at')->nullable();

            $table->timestamp('transfused_at')->nullable();
            $table->foreignId('transfused_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            // untagged_by null with untagged_at set is the scheduler.
            $table->timestamp('untagged_at')->nullable();
            $table->foreignId('untagged_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->enum('untag_reason', [
                'crossmatch_deadline_expired',
                'transfusion_deadline_expired',
                'released_by_staff',
            ])->nullable();
            $table->string('untag_note', 255)->nullable();

            // Only on an untagged_crossmatched tag: when staff confirmed the
            // bag was back in storage.
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->timestamps();

            $table->index(['hospital_unit_id', 'tagged_at']);
            $table->index(['facility_id', 'status']);
            $table->index(['facility_id', 'untagged_at']);
            $table->index(['status', 'crossmatch_deadline_at']);
            $table->index(['status', 'transfusion_deadline_at']);
            $table->index('transfusion_request_id');
        });

        match (Schema::getConnection()->getDriverName()) {
            'pgsql', 'sqlite' => DB::statement(
                'CREATE UNIQUE INDEX unit_tags_active_hospital_unit_unique '
                ."ON unit_tags (hospital_unit_id) WHERE status IN ('tag_assigned', 'tag_crossmatched')"
            ),
            default => null,
        };
    }

    public function down(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql', 'sqlite' => DB::statement('DROP INDEX IF EXISTS unit_tags_active_hospital_unit_unique'),
            default => null,
        };

        Schema::dropIfExists('unit_tags');
    }
};
