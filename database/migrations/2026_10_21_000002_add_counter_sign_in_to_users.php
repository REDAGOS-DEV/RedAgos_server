<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A sign-in handed to a walk-in donor at the counter (project owner,
     * 2026-10-21): the donor code or email and a temporary password on a slip,
     * so the donor signs in to the app without registering.
     *
     *  - sign_in_issued_at: the first time staff issued one. Never cleared. It
     *    says the donor's identity was checked in person, which is why sign-in
     *    does not wait for an email address to be confirmed.
     *  - password_change_required: the password in use is one staff issued.
     *    Every endpoint but the password change refuses until it is replaced.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('sign_in_issued_at')->nullable()->after('activated_at');
            $table->boolean('password_change_required')->default(false)->after('sign_in_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['sign_in_issued_at', 'password_change_required']);
        });
    }
};
