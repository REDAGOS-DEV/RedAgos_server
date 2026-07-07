<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blood_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('blood_type_id')->constrained('blood_types')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('component_id')->constrained('blood_components')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('patient_name', 150);
            $table->string('patient_identifier', 100)->nullable();
            $table->unsignedInteger('quantity');
            $table->enum('urgency_level', ['routine', 'emergency'])->default('routine');
            $table->enum('status', ['pending', 'processing', 'partial', 'fulfilled', 'rejected', 'cancelled'])->default('pending');
            $table->dateTime('request_date')->useCurrent();
            $table->timestamps();

            $table->index(['facility_id', 'request_date']);
            $table->index(['blood_type_id', 'component_id', 'status']);
            $table->index(['status', 'urgency_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_requests');
    }
};