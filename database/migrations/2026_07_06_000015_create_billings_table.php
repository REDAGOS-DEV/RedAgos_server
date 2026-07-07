<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->unique()->constrained('blood_requests')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('billed_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->dateTime('billing_date')->useCurrent();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->enum('status', ['unpaid', 'partial', 'paid', 'void'])->default('unpaid');
            $table->timestamps();

            $table->index(['status', 'billing_date']);
            $table->index('billed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billings');
    }
};