<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eligibility_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained('donor_profiles', 'donor_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('question_version');
            $table->dateTime('screened_at');
            $table->dateTime('valid_until');
            $table->enum('result', ['eligible', 'deferred', 'pending', 'expired'])->default('pending');
            $table->enum('computed_result', ['eligible', 'deferred'])->nullable();
            $table->string('submitted_result', 20)->nullable();
            $table->unsignedSmallInteger('age_at_screening')->nullable();
            $table->unsignedSmallInteger('weight_kg')->nullable();
            $table->date('declared_last_donation_date')->nullable();
            $table->json('deferral_reasons')->nullable();
            $table->timestamps();

            $table->index(['donor_id', 'screened_at']);
            $table->index(['donor_id', 'result', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibility_screenings');
    }
};
