<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('email_verified_at');
            $table->string('account_status', 30)->default('pending_activation')->after('password');
            $table->timestamp('activated_at')->nullable()->after('account_status');

            $table->index('account_status');
        });

        Schema::table('donor_profiles', function (Blueprint $table) {
            $table->string('valid_id_number', 50)->nullable()->change();
            $table->string('gender', 20)->nullable()->after('blood_type_id');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('address', 255)->nullable()->after('birth_date');

            $table->index(['gender', 'birth_date']);
        });
    }

    public function down(): void
    {
        Schema::table('donor_profiles', function (Blueprint $table) {
            $table->dropIndex(['gender', 'birth_date']);
            $table->dropColumn(['gender', 'birth_date', 'address']);
            $table->string('valid_id_number', 50)->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_status']);
            $table->dropColumn(['phone', 'account_status', 'activated_at']);
        });
    }
};
