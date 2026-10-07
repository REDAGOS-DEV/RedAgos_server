<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Name the weekly request a replenishment was sent as part of.
     *
     * Null on every request before this, on every STAT restock and on every
     * facility allocation. A weekly request is dispatched in one delivery, and
     * FulfillmentService reads this column to know it.
     */
    public function up(): void
    {
        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->foreignId('weekly_request_id')->nullable()->after('transfusion_request_id')
                ->constrained('weekly_requests')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('weekly_request_id');
        });
    }
};
