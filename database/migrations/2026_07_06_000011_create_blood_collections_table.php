<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blood_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->unique()->constrained('donations')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('collected_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->dateTime('collection_datetime');
            $table->timestamps();

            $table->index(['collected_by', 'collection_datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_collections');
    }
};