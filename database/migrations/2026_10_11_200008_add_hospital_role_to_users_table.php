<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give a hospital blood bank account a role: head, inventory_staff or requesting_staff.
     *
     * Added by the project owner on 2026-10-11 (HospitalRole). A string, as
     * staff_role is, validated by the application; null on every account that
     * is not a hospital's. assign_heads_to_hospital_accounts fills it for the
     * accounts that exist.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('hospital_role', 40)->nullable()->after('staff_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('hospital_role');
        });
    }
};
