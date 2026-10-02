<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The history of every blood request, one row per thing that happened to it.
     *
     * audit_logs is the security trail and carries identifiers only; it is not
     * something a hospital or a centre reads to understand where a request has
     * got to. This table is: each row names the event, who did it and from
     * which facility, the status it moved between, and a snapshot of every line
     * at that moment — requested, reserved, fulfilled, received, forwarded,
     * remaining — so the fulfilment of a request can be read back exactly as it
     * stood at each step.
     *
     * Append-only. There is no updated_at, and BloodRequestEvent refuses an
     * update.
     */
    public function up(): void
    {
        Schema::create('blood_request_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('request_id')
                ->constrained('blood_requests')->cascadeOnUpdate()->restrictOnDelete();

            $table->foreignId('request_item_id')->nullable()
                ->constrained('blood_request_items')->cascadeOnUpdate()->nullOnDelete();

            $table->string('event', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            // nullOnDelete: the event outlives the account. A null actor with no
            // facility is the scheduler.
            $table->foreignId('actor_id')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('actor_facility_id')->nullable()
                ->constrained('facilities')->cascadeOnUpdate()->nullOnDelete();

            // The other request an event concerns: a follow-up and its parent.
            $table->foreignId('related_request_id')->nullable()
                ->constrained('blood_requests')->cascadeOnUpdate()->nullOnDelete();

            $table->json('lines');
            $table->json('unit_ids')->nullable();
            $table->json('meta')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['request_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_request_events');
    }
};
