<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_type_id')->constrained('facility_types')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('address', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['facility_type_id', 'name']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};