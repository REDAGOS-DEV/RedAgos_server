<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a donation typed from a drive's paper sheet carries beyond a counter one.
 *
 *  - `donations.paper_sheet_id`: the sheet it was typed from. Null for every
 *    donation recorded at the counter, so non-null is what marks a paper entry.
 *  - `donation_screenings.screened_by_name` and
 *    `blood_collections.phlebotomist_name`: the physician and phlebotomist as
 *    the paper names them. `recorded_by` and `collected_by` stay the staff
 *    member who typed the record, their meaning everywhere else; at the counter
 *    the two are the same person, on a paper record they are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->foreignId('paper_sheet_id')->nullable()->after('appointment_id')
                ->constrained('paper_donation_sheets')->restrictOnDelete();
        });

        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->string('screened_by_name', 120)->nullable()->after('recorded_by');
        });

        Schema::table('blood_collections', function (Blueprint $table) {
            $table->string('phlebotomist_name', 120)->nullable()->after('collected_by');
        });
    }

    public function down(): void
    {
        Schema::table('blood_collections', function (Blueprint $table) {
            $table->dropColumn('phlebotomist_name');
        });

        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->dropColumn('screened_by_name');
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paper_sheet_id');
        });
    }
};
