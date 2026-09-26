<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Processing records each bag it separates, with its volume, instead of a count.
 *
 * A component breakdown used to be "Packed RBC x 2". It is now one row per
 * bag — "Packed RBC, 250 mL", "Packed RBC, 230 mL" — so two bags of the same
 * component are two rows, and the unique (donation_id, component_id) index
 * has to go. The bag's volume then travels with it into stock:
 * `blood_units.volume_ml` is filled at intake from the bag it was booked
 * against.
 *
 * `quantity` STAYS. Every new row carries 1, and rows declared before this
 * migration keep their count and a null volume. That keeps the one ledger
 * inventory intake is constrained by — declared bags, per component, as the
 * sum of `quantity` — true for old and new declarations alike, without
 * rewriting it.
 *
 * Index before unique: MySQL will not drop an index that the donation_id
 * foreign key still relies on, so the plain index goes in first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_components', function (Blueprint $table) {
            $table->unsignedSmallInteger('volume_ml')->nullable()->after('quantity');
            $table->index('donation_id', 'donation_components_donation_id_index');
        });

        Schema::table('donation_components', function (Blueprint $table) {
            $table->dropUnique('donation_components_donation_id_component_id_unique');
        });

        Schema::table('blood_units', function (Blueprint $table) {
            $table->unsignedSmallInteger('volume_ml')->nullable()->after('component_id');
        });
    }

    /**
     * Refuses rather than guesses when two bags of one component exist: the
     * unique index cannot come back without deciding which bag to delete.
     */
    public function down(): void
    {
        $duplicates = DB::table('donation_components')
            ->select('donation_id', 'component_id')
            ->groupBy('donation_id', 'component_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Some donations have more than one bag of the same component. Merge them before rolling back.'
            );
        }

        Schema::table('blood_units', function (Blueprint $table) {
            $table->dropColumn('volume_ml');
        });

        Schema::table('donation_components', function (Blueprint $table) {
            $table->unique(['donation_id', 'component_id']);
        });

        Schema::table('donation_components', function (Blueprint $table) {
            $table->dropIndex('donation_components_donation_id_index');
            $table->dropColumn('volume_ml');
        });
    }
};
