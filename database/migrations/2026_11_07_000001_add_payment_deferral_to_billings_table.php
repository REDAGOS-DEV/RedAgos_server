<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record that a request's units may go out before its invoice is settled.
     *
     * Decided at issuance (project owner, 2026-11-07). The statement keeps its
     * status and balance, so this is a decision beside them rather than a new
     * status. An issued revision freezes it, because it says when the invoice
     * is payable.
     */
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table): void {
            $table->timestamp('payment_deferred_at')->nullable();
            $table->foreignId('payment_deferred_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('billing_revisions', function (Blueprint $table): void {
            $table->boolean('payment_deferred')->default(false);
        });
    }

    /**
     * Forget the deferrals. A release they allowed stays released.
     */
    public function down(): void
    {
        Schema::table('billing_revisions', function (Blueprint $table): void {
            $table->dropColumn('payment_deferred');
        });

        Schema::table('billings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_deferred_by');
        });

        Schema::table('billings', function (Blueprint $table): void {
            $table->dropColumn('payment_deferred_at');
        });
    }
};
