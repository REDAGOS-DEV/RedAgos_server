<?php

use App\Enums\ScreeningOutcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a screening record which of the form's four REMARKS boxes was ticked.
 *
 * The column accepted `qualified` and `deferred`. Section I-D prints four
 * boxes — Accepted, Temporarily Deferred, Permanently Deferred and Indefinite
 * Deferral — and the difference between the second and the third is the
 * difference between a donor who should come back next month and one who must
 * never donate again. Two values cannot express that, and the system was
 * telling every deferred donor in writing that deferrals are usually temporary.
 *
 * Written per driver rather than through `enum()->change()`, which emits
 * `alter column ... type varchar(255) check (...)` on PostgreSQL and is
 * rejected: a check constraint cannot be declared inline in ALTER COLUMN.
 * Postgres therefore swaps the constraint; SQLite has no ALTER for one, so it
 * goes through the schema builder's table rebuild. Same shape as
 * 2026_09_21_120001_add_subsidised_status_to_billings_table.
 *
 * IDEMPOTENT ON PURPOSE. The migration that created this table called
 * ScreeningOutcome::values() at migration time, so the accepted set was baked
 * from whatever the enum held when it ran. Now that the enum has four cases, a
 * fresh `migrate:fresh` builds the four-value column directly and arrives here
 * with nothing to widen and no legacy rows to rewrite — which must be a no-op
 * rather than an error, or CI and an existing database diverge.
 */
return new class extends Migration
{
    /**
     * What each superseded value becomes.
     */
    private const REWRITE = [
        'qualified' => 'accepted',
        // The only honest reading. Every deferral on record was entered when
        // temporariness was the only thing the system could mean by the word,
        // and DonorDeferred told those donors exactly that.
        'deferred' => 'temporarily_deferred',
    ];

    public function up(): void
    {
        // Widen before rewriting: the rows cannot hold the new values until the
        // constraint allows them.
        $this->setAccepted(array_merge(ScreeningOutcome::values(), array_keys(self::REWRITE)));

        foreach (self::REWRITE as $from => $to) {
            DB::table('donation_screenings')->where('outcome', $from)->update(['outcome' => $to]);
        }

        // Narrow to exactly the four the form prints.
        $this->setAccepted(ScreeningOutcome::values());
    }

    /**
     * Narrow the column back, refusing rather than guessing at a deferral.
     *
     * A permanent or indefinite deferral cannot be mapped onto the old values
     * without asserting something untrue about it: `deferred` meant temporary
     * everywhere it was read, and rolling one back would tell that donor to
     * book again. Failing loudly is the honest outcome; those rows have to be
     * decided by a person.
     */
    public function down(): void
    {
        $blocking = DB::table('donation_screenings')
            ->whereIn('outcome', [
                ScreeningOutcome::PermanentlyDeferred->value,
                ScreeningOutcome::IndefiniteDeferral->value,
            ])
            ->count();

        if ($blocking > 0) {
            throw new RuntimeException(
                "donation_screenings holds {$blocking} permanent or indefinite deferral(s), which the "
                .'earlier column can only express as a temporary one. Decide what each should become '
                .'before rolling this back.'
            );
        }

        $this->setAccepted(array_merge(['qualified', 'deferred'], ScreeningOutcome::values()));

        foreach (self::REWRITE as $to => $from) {
            DB::table('donation_screenings')->where('outcome', $from)->update(['outcome' => $to]);
        }

        $this->setAccepted(['qualified', 'deferred']);
    }

    /**
     * Rewrite the set of values the outcome column accepts.
     *
     * @param  array<int, string>  $accepted
     */
    private function setAccepted(array $accepted): void
    {
        $accepted = array_values(array_unique($accepted));
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $quoted = implode(', ', array_map(
                fn (string $value): string => $connection->getPdo()->quote($value),
                $accepted
            ));

            DB::statement('ALTER TABLE donation_screenings DROP CONSTRAINT IF EXISTS donation_screenings_outcome_check');
            DB::statement(
                "ALTER TABLE donation_screenings ADD CONSTRAINT donation_screenings_outcome_check CHECK (outcome IN ({$quoted}))"
            );

            return;
        }

        Schema::table('donation_screenings', function (Blueprint $table) use ($accepted): void {
            // No default, matching the original column: an outcome is always
            // the officer's explicit choice.
            $table->enum('outcome', $accepted)->change();
        });
    }
};
