<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a bag trace to a direct-distribution delivery instead of a donation.
     *
     * Until now every blood_units row came from a donation collected at a
     * RedAgos centre, and donation_id was NOT NULL to say so. A bag a hospital
     * received from outside RedAgos — the Philippine Red Cross, say — has no
     * donation here, so it traces to the delivery that brought it instead.
     * Exactly one of the two is set on every row: BloodUnit's saving hook
     * enforces that everywhere, and on PostgreSQL a CHECK constraint enforces
     * it in the database as well.
     *
     * MySQL is left to the model. It refuses a CHECK over a column whose
     * foreign key carries a referential action, and both of these do.
     *
     * Kept as two statements on purpose: sqlite rebuilds the table for a
     * column change, and adding a column in the same pass would ask it to
     * rebuild around a column it has not created yet.
     */
    public function up(): void
    {
        Schema::table('blood_units', function (Blueprint $table): void {
            $table->foreignId('donation_id')->nullable()->change();
        });

        Schema::table('blood_units', function (Blueprint $table): void {
            $table->foreignId('direct_distribution_id')->nullable()->after('donation_id')
                ->constrained('direct_distributions')->cascadeOnUpdate()->restrictOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE blood_units ADD CONSTRAINT blood_units_origin_check '
                .'CHECK ((donation_id IS NULL) <> (direct_distribution_id IS NULL))'
            );
        }
    }

    /**
     * Refused while any bag traces to a delivery: making donation_id required
     * again would leave those bags with no origin at all.
     */
    public function down(): void
    {
        if (DB::table('blood_units')->whereNotNull('direct_distribution_id')->exists()) {
            throw new RuntimeException(
                'Cannot roll back: blood units recorded from direct distribution have no donation to fall back on.'
            );
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE blood_units DROP CONSTRAINT IF EXISTS blood_units_origin_check');
        }

        Schema::table('blood_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('direct_distribution_id');
        });

        Schema::table('blood_units', function (Blueprint $table): void {
            $table->foreignId('donation_id')->nullable(false)->change();
        });
    }
};
