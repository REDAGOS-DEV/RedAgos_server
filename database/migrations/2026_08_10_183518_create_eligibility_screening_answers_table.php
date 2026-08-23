<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Health data of the highest sensitivity in this system: infectious disease
     * status, surgery and transfusion history, and medication use.
     *
     * The answer is stored as ciphertext via the model's `encrypted` cast, so
     * the column is text rather than boolean and is deliberately not indexable.
     */
    public function up(): void
    {
        Schema::create('eligibility_screening_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screening_id')->constrained('eligibility_screenings')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('question_code', 20);
            $table->text('answer');
            $table->timestamp('created_at')->nullable();

            $table->unique(['screening_id', 'question_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibility_screening_answers');
    }
};
