<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The digital clearance tokens a unit needs before it may leave quarantine.
     *
     * Never deleted, and never edited except to be revoked — once — when an
     * approved correction replaces the result the token stood on. At most one
     * unrevoked token per (donation, kind), enforced under the donation lock
     * by ClearanceRepository, since a partial unique index is not portable.
     * issued_by is nullable because the backfill below vouches for donations
     * nobody re-examined, and saying so is more honest than naming someone.
     */
    public function up(): void
    {
        Schema::create('donation_clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->string('source', 40);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['donation_id', 'kind']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_clearances');
    }

    /**
     * Carry existing donations across without changing what they may do.
     *
     * - completed: cleared for issue under the old rule, and their units are
     *   already on the shelf as available. Both tokens, so nothing already in
     *   stock is stranded.
     * - tested: both sections recorded non-reactive under the old rule, which
     *   is exactly what the tokens now attest. A legacy donation that passed on
     *   the old single-result screen has no itemised panel, so no TTI token,
     *   and is sent back to `collected` for the panel to be recorded.
     *
     * Public so ClearanceBackfillTest can drive it.
     */
    public function backfill(): void
    {
        $now = now();

        $completed = DB::table('donations')->where('status', 'completed')->pluck('id');

        foreach ($completed as $donationId) {
            foreach (['tti', 'immunohematology'] as $kind) {
                $this->issue((int) $donationId, $kind, 'backfill_branch_a', $now);
            }
        }

        $tested = DB::table('donations')->where('status', 'tested')->pluck('id');
        $returned = [];

        foreach ($tested as $donationId) {
            $donationId = (int) $donationId;

            $typed = DB::table('donation_immunohematology')->where('donation_id', $donationId)->exists();

            $serology = DB::table('donation_serology')->where('donation_id', $donationId)->first();
            $clear = $serology !== null && collect(['hiv', 'hbsag', 'hcv', 'syphilis', 'malaria'])
                ->every(fn (string $marker): bool => $serology->{$marker} === 'non_reactive');

            if ($typed) {
                $this->issue($donationId, 'immunohematology', 'backfill_tested', $now);
            }

            if ($clear) {
                $this->issue($donationId, 'tti', 'backfill_tested', $now);
            }

            // `tested` now means both tokens exist.
            if (! ($typed && $clear)) {
                DB::table('donations')->where('id', $donationId)->update(['status' => 'collected']);
                $returned[] = $donationId;
            }
        }

        if ($returned !== []) {
            Log::warning(
                'Clearance backfill returned these tested donations to collected, because a test section was '
                .'missing: '.implode(', ', $returned).'.'
            );
        }
    }

    private function issue(int $donationId, string $kind, string $source, mixed $now): void
    {
        $exists = DB::table('donation_clearances')
            ->where('donation_id', $donationId)
            ->where('kind', $kind)
            ->whereNull('revoked_at')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('donation_clearances')->insert([
            'donation_id' => $donationId,
            'kind' => $kind,
            'issued_by' => null,
            'issued_at' => $now,
            'source' => $source,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
