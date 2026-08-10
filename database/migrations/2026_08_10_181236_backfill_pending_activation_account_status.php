<?php

use App\Enums\AccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('account_status', 'pending_activation')
            ->update(['account_status' => AccountStatus::PendingVerification->value]);

        DB::table('users')
            ->whereNotNull('email_verified_at')
            ->where('account_status', AccountStatus::PendingVerification->value)
            ->update(['account_status' => AccountStatus::Active->value]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('account_status', AccountStatus::PendingVerification->value)
            ->update(['account_status' => 'pending_activation']);
    }
};
