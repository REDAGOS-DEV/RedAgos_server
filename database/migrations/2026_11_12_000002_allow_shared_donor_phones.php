<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A mobile number may be shared by a household (project owner, 2026-11-12):
 * in remote areas one phone serves the whole family, so the second relative
 * could not be registered with the only number they have.
 *
 * Staff mark a donor's number Personal or Shared when they type it. A Personal
 * number still belongs to one account only, which the partial index below
 * holds even against two saves at once; Shared numbers may repeat. Existing
 * numbers were all unique, so every one starts as Personal. The donor portal
 * only ever saves Personal numbers.
 *
 * Nothing signs in or recovers an account by phone, and the same person is
 * kept from registering twice by the valid ID number and email, so sharing a
 * number loosens neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('phone_shared')->default(false)->after('phone');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->index('phone');
        });

        DB::statement(
            'CREATE UNIQUE INDEX users_phone_personal_unique ON users (phone) '
            .'WHERE phone_shared = false'
        );
    }

    /**
     * Fails while two accounts share a number: put them back on numbers of
     * their own first.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_phone_personal_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->unique('phone');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_shared');
        });
    }
};
