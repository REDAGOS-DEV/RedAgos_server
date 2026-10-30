<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a blood centre will meet a replenishment order (project owner, 2026-10-29).
     *
     * A restock is not answered like a patient's request. Before anything is
     * reserved the centre says what it can supply of each line, whether the
     * hospital picks the order up or the centre delivers it, and when. The
     * hand-over lives here, one row per request; what each line will get lives
     * on the line, beside what it asked for, which never changes.
     */
    public function up(): void
    {
        Schema::create('supply_plans', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('request_id')->unique()
                ->constrained('blood_requests')->cascadeOnUpdate()->cascadeOnDelete();

            // pickup or delivery (HandoverMethod).
            $table->string('handover_method', 20);

            // When the hospital collects it, or when the centre arrives with it.
            $table->timestamp('scheduled_at');

            // Where it is delivered to, or where at the centre it is collected.
            $table->string('handover_address', 255)->nullable();

            // Who at the centre the hospital calls about it.
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();

            // For the hospital: "bring a validated transport box".
            $table->text('notes')->nullable();

            $table->foreignId('planned_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->timestamps();

            $table->index('scheduled_at');
        });

        Schema::table('blood_request_items', function (Blueprint $table): void {
            // What the centre will supply of the line; null until it plans the order.
            $table->unsignedInteger('committed_quantity')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->dropColumn('committed_quantity');
        });

        Schema::dropIfExists('supply_plans');
    }
};
