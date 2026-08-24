<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hold the clinical values that unit expiry will later be derived from.
     *
     * Both columns are nullable with no default, and BloodComponentSeeder
     * leaves them NULL, on purpose. Shelf life varies by component preparation,
     * anticoagulant and storage protocol, so a generic value must never
     * silently become production truth. Module 2 fails loudly on a NULL rather
     * than falling back to a default.
     */
    public function up(): void
    {
        Schema::table('blood_components', function (Blueprint $table) {
            $table->unsignedSmallInteger('shelf_life_days')->nullable()->after('name');
            $table->string('storage_temperature', 50)->nullable()->after('shelf_life_days');
        });
    }

    public function down(): void
    {
        Schema::table('blood_components', function (Blueprint $table) {
            $table->dropColumn(['shelf_life_days', 'storage_temperature']);
        });
    }
};
