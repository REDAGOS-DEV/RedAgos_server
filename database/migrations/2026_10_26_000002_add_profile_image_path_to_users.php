<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff member's own profile photo, shown in the portal header.
 *
 * The path of a file on the private `local` disk, never a public URL: like a
 * donor's photo (donor_profiles.profile_image_path), it is served through a
 * short-lived signed route. Nullable — staff without one show their initials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_image_path', 255)->nullable()->after('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_image_path');
        });
    }
};
