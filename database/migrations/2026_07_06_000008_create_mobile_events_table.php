<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('name', 150);
            $table->string('location', 150);
            $table->date('event_date');
            $table->unsignedInteger('max_capacity')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['facility_id', 'event_date']);
            $table->index('event_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_events');
    }
};