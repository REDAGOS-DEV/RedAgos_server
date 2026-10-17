<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A drive's paper records, entered after the fact (project owner, 2026-10-17).
 *
 * A drive in a remote area has no connection, so its screening and phlebotomy
 * are written on paper and typed in when the sheets and bags come back. One row
 * is one stack of paper: the drive it came from, the day it was held, and the
 * names the entry form starts each donor with. Every donation typed from it
 * points back here through `donations.paper_sheet_id`.
 *
 *  - `mobile_event_id`: the drive, when it was planned in RedAgos. Otherwise
 *    `drive_name` and `location` say where it was, as written on the paper.
 *  - `starts_at`: when the drive opened, the time a donor's visit is dated at
 *    when the paper gives no screening time.
 *  - `screened_by_name`, `phlebotomist_name`: the names most rows carry, as the
 *    paper prints them. Free text: the owner chose names as written over
 *    picking staff accounts.
 *  - `notify_donors`: whether each donor is sent the counter's thank-you or
 *    deferral notice. Unticked for an old record, which would otherwise mail
 *    someone about a donation from months ago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_donation_sheets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('mobile_event_id')->nullable()->constrained('mobile_events')->nullOnDelete();

            $table->string('drive_name', 150);
            $table->string('location', 255)->nullable();

            $table->date('held_on');
            $table->time('starts_at');

            $table->string('screened_by_name', 120)->nullable();
            $table->string('phlebotomist_name', 120)->nullable();

            $table->boolean('notify_donors')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->index(['facility_id', 'held_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_donation_sheets');
    }
};
