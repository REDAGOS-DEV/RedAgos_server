<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Drive pre-registration (project owner, 2026-11-11 and 2026-11-12): people
 * without a portal account put themselves, or a partner puts its people, on a
 * drive's list through the drive's registration link. Staff create the drive
 * and read the list; they do not type entries in.
 *
 *  - `mobile_events.registration_token`: the link's secret part. Opaque, so
 *    drives cannot be walked by id, and only a published drive answers to it.
 *  - `drive_partner_submissions`: one partner's batch through the link, with
 *    the organisation and its contact as typed. No partner accounts.
 *  - `drive_pre_registrations`: one person on the list. Planning data only: a
 *    name, at least one contact, optionally a birth date and affiliation. No
 *    health answers: a person without a portal account is screened in person
 *    at the drive. `donor_id` is set when staff confirm the person is an
 *    existing donor; `appointment_id` when the person is booked or checked in
 *    for the drive, after which the booking stands for them. Nothing here
 *    creates an account, appointment or donation by itself.
 *
 * Unconverted entries are anonymised 90 days after their drive
 * (drives:anonymise-pre-registrations), so the names go and the counts stay;
 * that is why the personal columns are nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_events', function (Blueprint $table) {
            $table->string('registration_token', 40)->nullable()->unique()->after('cancellation_reason');
        });

        DB::table('mobile_events')->whereNull('registration_token')->orderBy('id')->pluck('id')
            ->each(fn (int $id) => DB::table('mobile_events')->where('id', $id)->update(['registration_token' => Str::random(32)]));

        Schema::create('drive_partner_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mobile_event_id')->constrained('mobile_events')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('organisation_name', 150);
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->timestamp('consent_at');
            $table->timestamp('anonymised_at')->nullable();
            $table->timestamps();

            $table->index('mobile_event_id');
        });

        Schema::create('drive_pre_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mobile_event_id')->constrained('mobile_events')->cascadeOnUpdate()->cascadeOnDelete();
            $table->enum('source', ['self', 'partner']);
            $table->enum('status', ['pre_registered', 'withdrawn'])->default('pre_registered');
            $table->foreignId('partner_submission_id')->nullable()
                ->constrained('drive_partner_submissions')->cascadeOnUpdate()->nullOnDelete();

            $table->string('first_name', 150)->nullable();
            $table->string('last_name', 150)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('affiliation', 150)->nullable();
            $table->timestamp('consent_at')->nullable();

            $table->foreignId('donor_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('matched_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('appointment_id')->nullable()
                ->constrained('donation_appointments')->cascadeOnUpdate()->nullOnDelete();

            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('anonymised_at')->nullable();
            $table->timestamps();

            $table->index(['mobile_event_id', 'status']);
            $table->index(['mobile_event_id', 'email']);
            $table->index(['mobile_event_id', 'phone']);
            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_pre_registrations');
        Schema::dropIfExists('drive_partner_submissions');

        Schema::table('mobile_events', function (Blueprint $table) {
            $table->dropUnique(['registration_token']);
            $table->dropColumn('registration_token');
        });
    }
};
