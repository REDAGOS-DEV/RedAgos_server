<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Donors the Testing department must follow up after a reactive serology result.
 *
 * Section I-C of the DOH form has the donor agree that "if found reactive, I
 * agreed to be referred to the appropriate facility for counselling and for
 * further management". This is that list. A row is opened automatically, in
 * the same transaction that rejects the donation, and the Testing department
 * works it: contacted, referred, closed.
 *
 * IT IS ALSO THE LABORATORY'S DEFERRAL. A reactive marker permanently defers
 * the donor. There is no separate deferral row: the prior-deferral lookup reads
 * this table alongside the screening outcomes. It cannot drift from the
 * result, because it is written with the rejection and the serology is locked
 * once the donation is rejected. Closing a referral does not lift the
 * deferral — "closed" means the follow-up is finished, not that the donor may
 * give blood again.
 *
 * WHICH MARKER IS NOT STORED HERE. It is read from `donation_serology`, which
 * already holds it; a copy would be a second place for the most sensitive fact
 * in the system to live. The note is encrypted at rest (cast on the model),
 * for the same reason the questionnaire answers are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counselling_referrals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('donation_id')->unique()
                ->constrained('donations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('donor_id')
                ->constrained('donor_profiles', 'donor_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            $table->enum('status', ['pending', 'contacted', 'referred', 'closed'])->default('pending');
            $table->dateTime('contacted_at')->nullable();
            $table->dateTime('referred_at')->nullable();
            $table->dateTime('closed_at')->nullable();

            // Encrypted by the model cast, so the column holds ciphertext and
            // is wider than the 500 characters the request accepts.
            $table->text('note')->nullable();

            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->timestamps();

            $table->index(['facility_id', 'status', 'created_at']);
            $table->index('donor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counselling_referrals');
    }
};
