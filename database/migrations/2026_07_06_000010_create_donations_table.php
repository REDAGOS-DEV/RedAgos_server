<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained('donor_profiles', 'donor_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('donation_appointments')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('donation_date');
            $table->enum('status', ['registered', 'screening', 'collected', 'tested', 'completed', 'rejected'])->default('registered');
            $table->timestamps();

            $table->index(['facility_id', 'donation_date']);
            $table->index(['donor_id', 'donation_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};