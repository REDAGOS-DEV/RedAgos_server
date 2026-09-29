<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One component a patient needs, and how many units in all.
     *
     * quantity is the overall requirement — five units of packed cells — and is
     * never changed afterwards. How much of it has been asked of which centre,
     * approved, released and received is read from the allocation lines that
     * point back here.
     *
     * The hospital may close the rest of a line it no longer needs; that is
     * recorded beside the quantity, never by lowering it.
     */
    public function up(): void
    {
        Schema::create('transfusion_request_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('transfusion_request_id')
                ->constrained('transfusion_requests')->cascadeOnUpdate()->cascadeOnDelete();

            $table->foreignId('component_id')
                ->constrained('blood_components')->cascadeOnUpdate()->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // The physician's certified indication, copied onto every
            // allocation line so each centre's DOH form carries it.
            $table->string('indication_code', 10)->nullable();
            $table->string('indication_other', 255)->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('closure_note', 255)->nullable();

            $table->timestamps();

            $table->unique(['transfusion_request_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfusion_request_items');
    }
};
