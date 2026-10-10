<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The blood centre's own Xendit sub-account.
     *
     * Each blood centre collects into its own sub-account under the RedAgos
     * master account (XenPlatform), decided by the project owner on 2026-10-10.
     * The master key creates checkouts on the centre's behalf by sending this
     * id as the for-user-id header. Null means the centre has no sub-account
     * yet, and gateway checkout is unavailable there; cash is unaffected.
     *
     * Not a secret: it identifies an account, it authorises nothing. Set by
     * the platform operator through facility:set-xendit-account.
     */
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('xendit_sub_account_id', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropUnique(['xendit_sub_account_id']);
        });

        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('xendit_sub_account_id');
        });
    }
};
