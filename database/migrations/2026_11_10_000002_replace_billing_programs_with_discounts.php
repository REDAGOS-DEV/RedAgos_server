<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The centre's own discounts, applied at the billing counter (project owner,
 * 2026-11-10), in place of the dated billing programmes.
 *
 * - billing_discounts: the list the Billing Supervisor keeps, each a
 *   percentage or a fixed peso amount, switched on or off.
 * - billings: the discount on a bill, copied when it is applied, so editing or
 *   switching off a discount later never changes a bill it was given to.
 *   `discount_amount` is what it takes off now; a percentage is worked out
 *   again as units are tagged or let go.
 * - billing_revisions: what an issued invoice said it took off. Added columns
 *   only: the revisions stay immutable.
 * - billing_programs is dropped. Its history stays in the audit log
 *   (billing.program_created, billing.program_updated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name', 100);
            $table->enum('kind', ['percentage', 'fixed']);
            $table->decimal('value', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['facility_id', 'name']);
            $table->index(['facility_id', 'is_active']);
        });

        Schema::table('billings', function (Blueprint $table): void {
            $table->foreignId('discount_id')->nullable()->constrained('billing_discounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('discount_name', 100)->nullable();
            $table->string('discount_kind', 20)->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_note', 255)->nullable();
            $table->foreignId('discounted_by')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('discounted_at')->nullable();
        });

        Schema::table('billing_revisions', function (Blueprint $table): void {
            $table->string('discount_name', 100)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
        });

        Schema::dropIfExists('billing_programs');
    }

    public function down(): void
    {
        Schema::create('billing_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name', 100);
            $table->enum('kind', ['government_subsidy', 'percentage_discount', 'fixed_discount']);
            $table->decimal('value', 12, 2)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->index(['facility_id', 'kind', 'is_enabled']);
            $table->index(['facility_id', 'effective_from', 'effective_to']);
        });

        Schema::table('billing_revisions', function (Blueprint $table): void {
            $table->dropColumn(['discount_name', 'discount_amount']);
        });

        Schema::table('billings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('discount_id');
            $table->dropConstrainedForeignId('discounted_by');
            $table->dropColumn(['discount_name', 'discount_kind', 'discount_value', 'discount_amount', 'discount_note', 'discounted_at']);
        });

        Schema::dropIfExists('billing_discounts');
    }
};
