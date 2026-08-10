<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only the SHA-256 hash of a check-in token is stored; the raw value is
     * returned once at issue time and never persisted.
     */
    public function up(): void
    {
        Schema::create('donor_qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained('donor_profiles', 'donor_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('screening_id')->constrained('eligibility_screenings')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->dateTime('issued_at');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['donor_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_qr_tokens');
    }
};
