<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_incentives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained('donor_profiles', 'donor_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('description', 255)->nullable();
            $table->boolean('claimed')->default(false);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['donor_id', 'claimed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_incentives');
    }
};