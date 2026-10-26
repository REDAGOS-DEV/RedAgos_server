<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A blood centre's deferral codes (project owner, 2026-10-24): each code
 * stands for one reason, so a deferral record shows the donor "R1" and never
 * the reason itself. The Screening Physician keeps the list
 * (deferrals.configure); DeferralCodeService seeds a centre's first list.
 *
 *  - `code`: what the record and the donor see (R1, R2-Low). Stored as
 *    typed, unique per centre whatever the case, retired ones included, so
 *    a code given to a donor never comes to mean something else.
 *  - `reason`: what the code stands for. Records copy it when they are
 *    written, so changing the wording here leaves past records as they were.
 *  - `retired_at`: no longer offered when recording. Kept, never deleted,
 *    because past records carry the code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deferral_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('reason', 255);
            $table->timestamp('retired_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['facility_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deferral_codes');
    }
};
