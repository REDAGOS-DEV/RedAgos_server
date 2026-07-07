<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_id')->constrained('billings')->cascadeOnUpdate()->cascadeOnDelete();
            $table->decimal('amount_paid', 10, 2);
            $table->enum('payment_method', ['cash', 'gcash']);
            $table->string('reference_number', 100)->nullable()->unique();
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('completed');
            $table->dateTime('payment_date')->useCurrent();
            $table->timestamps();

            $table->index(['billing_id', 'payment_date']);
            $table->index(['payment_method', 'payment_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};