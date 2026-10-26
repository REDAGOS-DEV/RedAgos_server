<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Final labelling moves to Processing (project owner, 2026-10-22): the
     * component lab prints the final labels once Testing has cleared the
     * donation, then hands the labelled bags over. Issuance books them
     * straight into stock.
     *
     *  - labelled_at / labelled_by: when, and by whom, the final labels were
     *    last printed. Hand-over requires them. A correction that changes what
     *    a label says (the breakdown, the typing, the serology or the
     *    collection) clears them, so the labels are printed again.
     */
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table): void {
            $table->timestamp('labelled_at')->nullable()->after('rejection_reason');
            $table->foreignId('labelled_by')->nullable()->after('labelled_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('labelled_by');
            $table->dropColumn('labelled_at');
        });
    }
};
