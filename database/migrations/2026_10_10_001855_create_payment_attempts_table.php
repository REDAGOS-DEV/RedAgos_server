<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gateway checkout attempts, kept apart from the payments they may become.
     *
     * A payment is money received. An attempt is a hosted checkout opened with
     * the payment provider that may yet be paid, expire, be cancelled or fail.
     * Summing payments must never count an attempt, so attempts have their own
     * table, and a confirmed attempt produces exactly one payment row
     * (payments.payment_attempt_id is unique).
     *
     * Each attempt is pinned to one immutable statement revision: its amount is
     * that revision's amount due, never a figure from the request body.
     *
     * At most one attempt per statement is open (creating, active or
     * awaiting_verification), enforced by a partial unique index so two
     * simultaneous checkouts cannot both open one.
     *
     * payer_name is the watcher's name as billing staff typed it, sent to the
     * provider as the customer; it is personal data and stored encrypted.
     *
     * Values are literals rather than application enums.
     */
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_id')->constrained('billings')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('billing_revision_id')->constrained('billing_revisions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('initiator_facility_id')->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('provider', 20)->default('xendit');
            $table->string('provider_account_id', 64);
            $table->string('reference_id', 64)->unique();
            $table->string('provider_session_id', 64)->nullable()->unique();
            $table->string('provider_payment_request_id', 64)->nullable()->unique();
            $table->string('provider_payment_id', 64)->nullable()->unique();
            $table->unsignedBigInteger('amount_centavos');
            $table->char('currency', 3)->default('PHP');
            $table->text('payer_name');
            $table->enum('status', [
                'creating', 'active', 'awaiting_verification', 'completed',
                'expired', 'canceled', 'failed', 'superseded',
            ])->default('creating');
            $table->text('checkout_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->unsignedInteger('verification_attempts')->default(0);
            $table->timestamp('next_verification_at')->nullable();
            $table->timestamp('verification_deadline_at')->nullable();
            $table->timestamp('review_required_at')->nullable();
            $table->string('review_reason', 60)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->index(['billing_id', 'status']);
            $table->index(['status', 'next_verification_at']);
        });

        // Both drivers support a partial index, and this is the one guarantee
        // a lock alone cannot give on SQLite, where lockForUpdate() is a no-op.
        DB::statement(
            'CREATE UNIQUE INDEX payment_attempts_one_open_per_billing ON payment_attempts (billing_id) '
            ."WHERE status IN ('creating', 'active', 'awaiting_verification')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
