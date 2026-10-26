<?php

namespace App\Console\Commands;

use App\Service\BillingLedger;
use App\Service\StatementFigures;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Check that every bill's journal adds up to what the bill says it owes.
 *
 * The journal is written in the same transaction as every change to a bill,
 * so this should always find nothing. Anything it finds is a defect to
 * investigate: the journal is append-only and is never corrected by hand.
 */
class VerifyBillingLedger extends Command
{
    protected $signature = 'billing:verify-ledger {--facility= : Check one blood centre only}';

    protected $description = 'Report bills whose billing journal does not add up to their balance';

    public function handle(BillingLedger $ledger, StatementFigures $figures): int
    {
        $facility = $this->option('facility');
        $drift = $ledger->drift($figures, $facility === null ? null : (int) $facility);

        if ($drift->isEmpty()) {
            $this->info('Every bill\'s journal adds up to its balance.');

            return self::SUCCESS;
        }

        $this->table(
            ['Billing', 'Request', 'Journal', 'Expected'],
            $drift->map(fn (array $row): array => [
                $row['billing_id'],
                $row['request_id'],
                Money::toDecimal($row['journal']),
                Money::toDecimal($row['expected']),
            ])->all()
        );

        $this->error("{$drift->count()} bill(s) do not add up. Investigate; never edit the journal.");

        return self::FAILURE;
    }
}
