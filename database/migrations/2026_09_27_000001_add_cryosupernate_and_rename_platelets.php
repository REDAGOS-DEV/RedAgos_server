<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring the component catalogue in line with the DOH Daily Blood Stock Inventory sheet.
 *
 * The sheet reports "Platelet Concentrate", and a fifth plasma product,
 * "Cryosupernate" (cryo-poor plasma), that the catalogue did not have. The
 * catalogue only ever reached a database through BloodComponentSeeder, which
 * nobody re-runs on a live system, so both changes are made here as data.
 *
 * RENAMED, NOT REPLACED. Every unit, request item and facility setting points
 * at the platelets row by id, so the row keeps its id and only its name
 * changes. IndicationCode's P1-P6 and the Blood Request Form PDF look the
 * component up by name and were changed alongside this migration.
 *
 * Both steps are safe to run against a database in any state: the rename only
 * happens when "Platelet Concentrate" is free (the name is unique), and
 * Cryosupernate is inserted only if absent — restored if it was soft-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $taken = DB::table('blood_components')->where('name', 'Platelet Concentrate')->exists();

        if (! $taken) {
            DB::table('blood_components')
                ->where('name', 'Platelets')
                ->update(['name' => 'Platelet Concentrate', 'updated_at' => now()]);
        }

        // Only a catalogue that already exists gains the row. An empty table
        // is a fresh database, which gets the whole catalogue — Cryosupernate
        // included — from BloodComponentSeeder, like every other component.
        if (! DB::table('blood_components')->exists()) {
            return;
        }

        $cryosupernate = DB::table('blood_components')->where('name', 'Cryosupernate')->first();

        if ($cryosupernate === null) {
            DB::table('blood_components')->insert([
                'name' => 'Cryosupernate',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif ($cryosupernate->deleted_at !== null) {
            DB::table('blood_components')
                ->where('id', $cryosupernate->id)
                ->update(['deleted_at' => null, 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the rename only. Cryosupernate stays: units and settings may
     * already point at it, and removing a catalogue row they reference would
     * orphan them.
     */
    public function down(): void
    {
        $taken = DB::table('blood_components')->where('name', 'Platelets')->exists();

        if (! $taken) {
            DB::table('blood_components')
                ->where('name', 'Platelet Concentrate')
                ->update(['name' => 'Platelets', 'updated_at' => now()]);
        }
    }
};
