<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The receipt of blood from outside RedAgos, under the identifier its sender gave it.
     *
     * Two ways in. Received for a Patient Transfusion Request, it is one bag,
     * scanned or typed. Received without one, it is typed by hand: one
     * identifier for as many bags as arrived (quantity), and who asked for
     * them (requested_for) — a ward, a physician, a patient's name.
     *
     * RedAgos does not replace the identifier with a barcode of its own. It is
     * identified by its source plus that identifier, so the unique index is
     * over both: two services may print the same number.
     */
    public function up(): void
    {
        Schema::create('direct_distributions', function (Blueprint $table): void {
            $table->id();

            // The hospital blood bank that received the blood.
            $table->foreignId('facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            // The patient's requirement it was received for, when there is one.
            $table->foreignId('transfusion_request_id')->nullable()
                ->constrained('transfusion_requests')->cascadeOnUpdate()->restrictOnDelete();

            // Who asked for it, when there is no requirement to name them.
            $table->string('requested_for', 150)->nullable();

            $table->foreignId('external_blood_source_id')
                ->constrained('external_blood_sources')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('external_unit_number', 50);

            // How many bags arrived under the identifier. One for a scanned bag.
            $table->unsignedSmallInteger('quantity')->default(1);

            $table->date('collection_date')->nullable();

            $table->timestamp('received_at');
            $table->foreignId('received_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->timestamps();

            $table->unique(['external_blood_source_id', 'external_unit_number'], 'direct_distributions_source_number_unique');
            $table->index(['facility_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_distributions');
    }
};
