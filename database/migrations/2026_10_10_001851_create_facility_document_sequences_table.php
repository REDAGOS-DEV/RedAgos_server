<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One counter per facility per kind of financial document.
     *
     * Statements (SOA-) and payment receipts (AR-) are numbered per issuing
     * facility. The number is taken from this row under a row lock, as the last
     * lock of the issuing transaction, and the increment commits or rolls back
     * with the document it numbers — so committed numbers have no gaps.
     *
     * A counter row of its own rather than parsing the highest number issued
     * under a facility row lock, as request references are: the facility row is
     * already locked by request numbering, and reusing it here would join two
     * unrelated lock paths.
     */
    public function up(): void
    {
        Schema::create('facility_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->enum('kind', ['statement', 'receipt']);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['facility_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_document_sequences');
    }
};
