<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A staff member's request to change a record they already saved, and the decision on it.
     *
     * `changes` is the corrected payload, validated with the original write's
     * rules; `previous` is what the record said when the request was filed, so
     * the approver reviews a before-and-after rather than a bare new value.
     */
    public function up(): void
    {
        Schema::create('correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->foreignId('donation_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 30);
            $table->foreignId('requested_by')->constrained('users');
            $table->text('reason');
            $table->json('changes');
            $table->json('previous')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['facility_id', 'status']);
            $table->index(['donation_id', 'subject', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correction_requests');
    }
};
