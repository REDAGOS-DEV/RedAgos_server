<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained('donor_profiles', 'donor_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('mobile_events')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('appointment_datetime');
            $table->enum('status', ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'])->default('scheduled');
            $table->timestamps();

            $table->index(['facility_id', 'appointment_datetime']);
            $table->index(['donor_id', 'appointment_datetime']);
            $table->index(['status', 'appointment_datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_appointments');
    }
};