<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stop requiring the hospital's phone confirmation on a walk-in.
     *
     * The project owner removed the confirmation step from the walk-in form on
     * 2026-10-19. A walk-in recorded since then has no verifier, no number
     * called and no time of confirmation, so those columns, and who recorded
     * the confirmation, may be empty. Walk-ins recorded before keep theirs.
     */
    public function up(): void
    {
        Schema::table('blood_request_walk_ins', function (Blueprint $table): void {
            $table->string('verifier_name', 150)->nullable()->change();
            $table->string('verifier_position', 100)->nullable()->change();
            $table->string('verifier_contact', 30)->nullable()->change();
            $table->timestamp('verified_at')->nullable()->change();
            $table->unsignedBigInteger('verification_recorded_by')->nullable()->change();
        });
    }

    /**
     * Require them again. Fails while any walk-in recorded without a
     * confirmation remains, which is the honest outcome: there is nothing
     * true to put in those columns.
     */
    public function down(): void
    {
        Schema::table('blood_request_walk_ins', function (Blueprint $table): void {
            $table->string('verifier_name', 150)->nullable(false)->change();
            $table->string('verifier_position', 100)->nullable(false)->change();
            $table->string('verifier_contact', 30)->nullable(false)->change();
            $table->timestamp('verified_at')->nullable(false)->change();
            $table->unsignedBigInteger('verification_recorded_by')->nullable(false)->change();
        });
    }
};
