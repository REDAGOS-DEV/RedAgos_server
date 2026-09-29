<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A short code per component, for the bag numbers printed on labels.
 *
 * Every bag from one donation carries the same barcode sticker, so a bag is
 * told apart by what it holds: 1234567-PRBC, 1234567-FFP. The code is that
 * suffix. Nullable and unique: a component a centre adds later without one
 * falls back to its initials (BloodComponent::labelCode()).
 */
return new class extends Migration
{
    /**
     * The codes the blood-bank sheets use for the seeded catalogue.
     *
     * @var array<string, string>
     */
    private const CODES = [
        'Whole Blood' => 'WB',
        'Packed RBC' => 'PRBC',
        'Fresh Frozen Plasma' => 'FFP',
        'Platelet Concentrate' => 'PC',
        'Cryoprecipitate' => 'CRYO',
        'Washed RBC' => 'WRBC',
        'Cryosupernate' => 'CSP',
    ];

    public function up(): void
    {
        Schema::table('blood_components', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->unique()->after('name');
        });

        foreach (self::CODES as $name => $code) {
            DB::table('blood_components')->where('name', $name)->update(['code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('blood_components', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
