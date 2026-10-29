<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A hospital blood bank's generated Inventory Reports (project owner, 2026-10-29).
     *
     * Each one is kept as the PDF it was printed as, on the private disk, so a
     * past day's inventory re-downloads exactly as it stood then rather than
     * being rebuilt from today's shelf. The row says what it covered.
     */
    public function up(): void
    {
        Schema::create('hospital_inventory_reports', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('generated_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            // One sheet per blood type, or every type on one.
            $table->string('layout', 10);
            // What it covered, as printed: component and blood type codes.
            $table->json('components');
            $table->json('blood_types');
            // { crossmatched, uncrossmatched } bags on it.
            $table->json('totals');
            $table->unsignedSmallInteger('pages');

            $table->string('file_path');
            $table->unsignedInteger('file_size');

            $table->timestamps();

            $table->index(['facility_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_inventory_reports');
    }
};
