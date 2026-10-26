<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The webhook inbox: one row per delivery the payment provider sends.
     *
     * Deliberately minimal. The raw body is never stored, only its SHA-256
     * hash, and summary keeps just the fields reconciliation needs — status,
     * amount, currency, references and a failure code. Customer details stay
     * with the provider. The server never trusts this row anyway: it re-fetches
     * the session from the provider before anything is recorded.
     *
     * dedupe_key is the body hash of an authenticated delivery, unique per
     * provider, so a retried webhook is recognised and acknowledged without
     * being processed twice. A delivery whose callback token failed keeps only
     * its body_hash and outcome, and leaves dedupe_key null, so a forged copy
     * sent first can never mark the genuine delivery as a duplicate.
     */
    public function up(): void
    {
        Schema::create('payment_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('xendit');
            $table->string('event_type', 80)->nullable();
            $table->char('body_hash', 64);
            $table->char('dedupe_key', 64)->nullable();
            $table->string('provider_object_id', 64)->nullable();
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->cascadeOnUpdate()->nullOnDelete();
            $table->boolean('token_valid');
            $table->json('summary')->nullable();
            $table->enum('outcome', [
                'received', 'processed', 'pending_verification', 'duplicate',
                'ignored', 'unmatched', 'rejected', 'error',
            ])->default('received');
            $table->string('error', 255)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->unique(['provider', 'dedupe_key']);
            $table->index('provider_object_id');
            $table->index(['outcome', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_events');
    }
};
