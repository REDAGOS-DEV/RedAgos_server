<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('blood_requests')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('unit_id', 50)->unique();
            $table->dateTime('allocated_at')->useCurrent();
            $table->enum('status', ['allocated', 'released', 'cancelled'])->default('allocated');
            $table->timestamps();

            $table->foreign('unit_id')->references('id')->on('blood_units')->cascadeOnUpdate()->restrictOnDelete();
            $table->index(['request_id', 'allocated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_allocations');
    }
};