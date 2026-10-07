<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a bag enter a hospital's custody without a dispatched hold behind it.
     *
     * Receipt of a RedAgos delivery still names its hold. A bag received by
     * direct distribution never had one — its blood_units row names the
     * delivery instead — so the column is now nullable. It stays unique: a
     * hold is still received at most once.
     */
    public function up(): void
    {
        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->foreignId('request_allocation_id')->nullable()->change();
        });
    }

    /**
     * Refused while any bag in custody has no hold, which a NOT NULL column could not hold.
     */
    public function down(): void
    {
        if (DB::table('hospital_units')->whereNull('request_allocation_id')->exists()) {
            throw new RuntimeException(
                'Cannot roll back: hospital units received by direct distribution have no dispatched hold.'
            );
        }

        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->foreignId('request_allocation_id')->nullable(false)->change();
        });
    }
};
