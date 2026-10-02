<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give a blood request one line per component asked for.
     *
     * The DOH form lets a requester tick several components on one sheet, each
     * with its own indication code and its own unit count. The table held one
     * component_id and one quantity, which can express only the first of those
     * lines, so a three-component request had to become three requests with
     * three reference numbers and three sheets of paper.
     *
     * indication_code is nullable, and only here. Requests raised before this
     * table existed record no clinical indication, and the following migration
     * has to carry them over; inventing a code for them would put a criterion a
     * physician never certified onto a medical record. New submissions are
     * required to carry one by StoreBloodRequestRequest.
     */
    public function up(): void
    {
        Schema::create('blood_request_items', function (Blueprint $table): void {
            $table->id();

            // cascadeOnDelete, unlike every other FK on this workflow: a line is
            // part of its request rather than a record in its own right, and an
            // orphaned line describes a request that no longer exists.
            $table->foreignId('request_id')
                ->constrained('blood_requests')->cascadeOnUpdate()->cascadeOnDelete();

            $table->foreignId('component_id')
                ->constrained('blood_components')->cascadeOnUpdate()->restrictOnDelete();

            $table->unsignedInteger('quantity');

            $table->string('indication_code', 10)->nullable();
            $table->string('indication_other', 255)->nullable();

            $table->timestamps();

            // A component appears at most once per form. Two lines for the same
            // component are one line with the quantities added up, and allowing
            // both would make "how many units of X does this request want"
            // ambiguous for the allocator.
            $table->unique(['request_id', 'component_id']);

            // Allocation reads a request's lines in order on every hold.
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_request_items');
    }
};
