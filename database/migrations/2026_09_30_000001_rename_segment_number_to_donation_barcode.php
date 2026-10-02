<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The donation's one identifier is the barcode sticker, not a "segment number".
 *
 * The centre uses a sheet of pre-printed barcode stickers per donation, all
 * with the same number: on the DOH form's "Place Barcode Label Here" boxes, on
 * every bag and tube, and on the donor's CUE slip. That number is what the
 * counter scans, what the laboratory scans to find a tube, and what each bag's
 * unit number is built from. The column only ever held it, so it is named for
 * it. Values are kept as they are.
 *
 * The unique index is renamed with it: CollectionService matches the word
 * "barcode" in a unique violation to tell this one from any other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blood_collections', function (Blueprint $table) {
            $table->dropUnique('blood_collections_facility_segment_unique');
        });

        Schema::table('blood_collections', function (Blueprint $table) {
            $table->renameColumn('segment_number', 'donation_barcode');
        });

        Schema::table('blood_collections', function (Blueprint $table) {
            $table->unique(['facility_id', 'donation_barcode'], 'blood_collections_facility_barcode_unique');
        });
    }

    public function down(): void
    {
        Schema::table('blood_collections', function (Blueprint $table) {
            $table->dropUnique('blood_collections_facility_barcode_unique');
        });

        Schema::table('blood_collections', function (Blueprint $table) {
            $table->renameColumn('donation_barcode', 'segment_number');
        });

        Schema::table('blood_collections', function (Blueprint $table) {
            $table->unique(['facility_id', 'segment_number'], 'blood_collections_facility_segment_unique');
        });
    }
};
