<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow a donor registered at the counter to have no email address.
     *
     * A walk-in is identified by the valid ID they present, not by an inbox.
     * Requiring an address would either turn away a genuine donor or push staff
     * into inventing one, and an invented address is worse than none: it looks
     * verifiable, it looks contactable, and it is neither.
     *
     * The unique index stays. Both MySQL and PostgreSQL follow the SQL standard
     * in treating NULLs as distinct inside a unique constraint, so any number of
     * email-less donors coexist — the same property `unique(facility_id,
     * employee_id)` already relies on.
     *
     * Nothing else needs relaxing. Login, password reset and verification all
     * match on a supplied address, and `WHERE email = ?` never matches NULL, so
     * an email-less account is simply unreachable by those flows rather than
     * being a hole in them. Self-registration still requires an address:
     * RegisterDonorRequest is unchanged.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 150)->nullable()->change();
        });
    }

    /**
     * Restore the NOT NULL constraint.
     *
     * This fails if any email-less donor exists, which is correct: silently
     * inventing addresses to satisfy a rollback would corrupt donor records.
     * Reassign or remove those accounts first.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 150)->nullable(false)->change();
        });
    }
};
