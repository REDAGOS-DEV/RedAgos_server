<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blood_units', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->foreignId('facility_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('component_id')->constrained('blood_components')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('blood_type_id')->constrained('blood_types')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('donation_id')->constrained('donations')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('expiry_date');
            $table->enum('status', ['available', 'reserved', 'issued', 'expired', 'discarded'])->default('available');
            $table->timestamps();

            $table->index(['facility_id', 'blood_type_id', 'component_id', 'status']);
            $table->index(['expiry_date', 'status']);
            $table->index('donation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_units');
    }
};