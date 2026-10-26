<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make every existing hospital blood bank account its blood bank's Head.
     *
     * Until hospital roles existed every hospital account could do everything,
     * so Head — which still can — is the only role that takes nothing away.
     * The Head then gives colleagues narrower roles from the Staff page.
     *
     * Values are literals rather than application enums, so this migration
     * never depends on later application code.
     */
    public function up(): void
    {
        $hospitalAccounts = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('roles.name', 'blood_bank')
            ->pluck('role_user.user_id');

        DB::table('users')
            ->whereIn('id', $hospitalAccounts)
            ->whereNull('hospital_role')
            ->update(['hospital_role' => 'head']);
    }

    /**
     * Nothing to undo: dropping the column in add_hospital_role_to_users_table removes the roles.
     */
    public function down(): void {}
};
