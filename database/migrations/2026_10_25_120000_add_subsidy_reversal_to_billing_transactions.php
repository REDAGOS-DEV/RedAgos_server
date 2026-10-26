<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACCEPTED = [
        'charge', 'charge_adjustment', 'payment', 'payment_correction',
        'payment_void', 'subsidy', 'subsidy_reversal', 'external_settlement',
        'opening_balance',
    ];

    private const PREVIOUS = [
        'charge', 'charge_adjustment', 'payment', 'payment_correction',
        'payment_void', 'subsidy', 'external_settlement', 'opening_balance',
    ];

    public function up(): void
    {
        $this->setAccepted(self::ACCEPTED);
    }

    public function down(): void
    {
        if (DB::table('billing_transactions')->where('type', 'subsidy_reversal')->exists()) {
            throw new RuntimeException('Subsidy reversal journal rows exist and cannot be discarded during rollback.');
        }

        $this->setAccepted(self::PREVIOUS);
    }

    /** @param array<int, string> $accepted */
    private function setAccepted(array $accepted): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $quoted = implode(', ', array_map(fn (string $value): string => $connection->getPdo()->quote($value), $accepted));
            DB::statement('ALTER TABLE billing_transactions DROP CONSTRAINT IF EXISTS billing_transactions_type_check');
            DB::statement("ALTER TABLE billing_transactions ADD CONSTRAINT billing_transactions_type_check CHECK (type IN ({$quoted}))");
            return;
        }

        Schema::table('billing_transactions', function (Blueprint $table) use ($accepted): void {
            $table->enum('type', $accepted)->change();
        });

        if ($connection->getDriverName() === 'sqlite') {
            $this->restoreSqliteTriggers();
        }
    }

    private function restoreSqliteTriggers(): void
    {
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'billing_transactions_no_'.strtolower($operation);
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name}
                BEFORE {$operation} ON billing_transactions
                BEGIN
                    SELECT RAISE(ABORT, 'billing_transactions: a billing transaction cannot be changed or deleted');
                END
                SQL);
        }
    }
};
