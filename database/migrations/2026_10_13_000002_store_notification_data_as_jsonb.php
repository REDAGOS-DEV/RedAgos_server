<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store notifications.data as jsonb on PostgreSQL, so it can be queried by key.
     *
     * DonorNotificationService filters by data->category and marks past
     * appointment reminders read by data->appointment_datetime. Laravel's
     * notifications table keeps data as text, and PostgreSQL has no ->>
     * operator on text, so every unread-count call failed there (found
     * 2026-10-13). SQLite reads JSON out of text, which is why the tests never
     * saw it; it is left as it is.
     *
     * Every row was written by Laravel's database channel as encoded JSON, so
     * each one casts; a row that did not would stop the migration rather than
     * be lost.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};
