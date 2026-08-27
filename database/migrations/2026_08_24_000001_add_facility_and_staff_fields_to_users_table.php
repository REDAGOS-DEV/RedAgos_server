<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link staff accounts to the facility they work for.
     *
     * Donors and admins keep a null facility_id; only blood-centre and
     * blood-bank staff carry one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('facility_id')->nullable()->after('phone')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('employee_id', 50)->nullable()->after('facility_id');
            $table->string('position', 100)->nullable()->after('employee_id');

            // Leading column is facility_id, so this also serves the
            // where('facility_id', ...) lookups every isolated query performs;
            // no separate index is needed. MySQL and PostgreSQL both follow the
            // SQL standard in treating NULLs as distinct inside a unique
            // constraint, so the many users with neither a facility nor an
            // employee id do not collide with one another.
            $table->unique(['facility_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['facility_id', 'employee_id']);
            $table->dropForeign(['facility_id']);
            $table->dropColumn(['facility_id', 'employee_id', 'position']);
        });
    }
};
