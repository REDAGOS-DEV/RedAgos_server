<?php

use App\Enums\BillingStatus;
use App\Models\Billing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a statement record that the government met the cost.
     *
     * The column accepted unpaid, partial, paid and void. A blood centre funded
     * by subsidy releases blood without charging for it, and the only states
     * available to express that were Paid — which would let a revenue report
     * count fees nobody charged — or Void, which says the statement should
     * never have existed. Neither is true, so the column gains a value that is.
     *
     * Written per driver rather than through `enum()->change()`, which emits
     * `alter column ... type varchar(255) check (...)` on PostgreSQL and is
     * rejected: a check constraint cannot be declared inline in ALTER COLUMN.
     * Postgres therefore swaps the constraint; SQLite has no ALTER for one, so
     * it goes through the schema builder's table rebuild.
     */
    public function up(): void
    {
        $this->setAccepted(BillingStatus::values());
    }

    /**
     * Narrow the column back, refusing rather than rewriting settled statements.
     *
     * A subsidised row cannot be mapped onto the old values without asserting
     * something untrue about it — Paid claims money was collected, Void claims
     * the statement was a mistake. Failing loudly is the honest outcome; those
     * rows have to be decided by a person.
     */
    public function down(): void
    {
        $subsidised = Billing::query()
            ->where('status', BillingStatus::Subsidised->value)
            ->count();

        if ($subsidised > 0) {
            throw new RuntimeException(
                "billings holds {$subsidised} subsidised statement(s), which the earlier column cannot "
                .'express. Decide what each one should become before rolling this back.'
            );
        }

        $this->setAccepted(['unpaid', 'partial', 'paid', 'void']);
    }

    /**
     * Rewrite the set of values the status column accepts.
     *
     * @param  array<int, string>  $accepted
     */
    private function setAccepted(array $accepted): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $quoted = implode(', ', array_map(
                fn (string $value): string => $connection->getPdo()->quote($value),
                $accepted
            ));

            DB::statement('ALTER TABLE billings DROP CONSTRAINT IF EXISTS billings_status_check');
            DB::statement(
                "ALTER TABLE billings ADD CONSTRAINT billings_status_check CHECK (status IN ({$quoted}))"
            );

            return;
        }

        Schema::table('billings', function (Blueprint $table) use ($accepted): void {
            $table->enum('status', $accepted)
                ->default(BillingStatus::Unpaid->value)
                ->change();
        });
    }
};
