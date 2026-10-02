<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bag in a hospital blood bank's custody.
     *
     * One row per physical bag the hospital confirmed receipt of — never one
     * per state. Tag Assigned, Tag Crossmatched and the rest are this row's
     * status and the unit_tags rows hung off it, not separate stock records.
     *
     * Everything the bag itself says — blood type, component, volume, expiry —
     * stays on the centre's blood_units row and is read through unit_id, never
     * copied here. A tag cannot reset an expiry date it does not hold, and the
     * centre's side of the bag (`issued`, its own facility_id) is left exactly
     * as dispatch wrote it.
     *
     * unit_id and request_allocation_id are both unique: a dispatched hold is
     * never cancelled and its bag never re-allocated, so a bag is received at
     * most once. A return-to-centre flow would have to relax unit_id to a
     * partial index, as relax_request_allocation_unit_uniqueness did.
     *
     * Values hard-coded rather than read from HospitalUnitStatus, so a later
     * change to the enum cannot change what this migration did.
     */
    public function up(): void
    {
        Schema::create('hospital_units', function (Blueprint $table): void {
            $table->id();

            // The hospital blood bank holding the bag.
            $table->foreignId('facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('unit_id', 50)->unique();
            $table->foreign('unit_id')->references('id')->on('blood_units')
                ->cascadeOnUpdate()->restrictOnDelete();

            // The dispatched hold whose receipt put the bag here.
            $table->foreignId('request_allocation_id')->unique()
                ->constrained('request_allocations')->cascadeOnUpdate()->restrictOnDelete();

            $table->enum('status', [
                'available',
                'tag_assigned',
                'tag_crossmatched',
                'pending_return',
                'transfused',
                'expired',
                'discarded',
            ])->default('available');

            $table->timestamp('expired_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->foreignId('discarded_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('discard_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['facility_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_units');
    }
};
