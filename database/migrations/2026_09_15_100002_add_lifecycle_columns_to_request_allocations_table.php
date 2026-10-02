<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give an allocation the timestamps that carry dispatch and receipt.
     *
     * The original table records only that a unit was allocated. The workflow
     * needs three more moments: when the unit physically left the facility,
     * when the requesting facility confirmed it arrived, and when a hold was
     * given up. Recording them here rather than on blood_requests is what lets
     * a partly-dispatched request describe itself accurately — a request-wide
     * status column cannot say "three of five units are in transit".
     *
     * received_at is separate from released_at on purpose. Only the receiving
     * facility can assert that blood arrived; the releasing facility asserting
     * it on their behalf would make the chain of custody a formality.
     */
    public function up(): void
    {
        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->foreignId('allocated_by')->nullable()->after('allocated_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->timestamp('released_at')->nullable()->after('allocated_by');
            $table->foreignId('released_by')->nullable()->after('released_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->timestamp('received_at')->nullable()->after('released_by');
            $table->foreignId('received_by')->nullable()->after('received_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable()->after('received_by');
            $table->string('cancellation_reason', 255)->nullable()->after('cancelled_at');

            // Counting what a request has released and received is the hot read
            // behind every status recomputation.
            $table->index(['request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->dropIndex(['request_id', 'status']);

            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('released_by');
            $table->dropConstrainedForeignId('allocated_by');

            $table->dropColumn(['released_at', 'received_at', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
