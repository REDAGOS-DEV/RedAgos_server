<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a unit be held back until testing clears it.
     *
     * Processing no longer waits for test results — plasma has to be frozen
     * within hours of the draw — so a unit now reaches the shelf before its
     * donation is cleared. It sits in quarantine until the Inventory Control
     * Officer releases it on both clearance tokens.
     *
     * Values hard-coded rather than read from BloodUnitStatus, so a later
     * change to the enum cannot change what this migration did. Written per
     * driver for the reason given in the billings subsidy migration.
     */
    private const WITH_QUARANTINE = ['available', 'reserved', 'issued', 'expired', 'discarded', 'quarantined'];

    private const WITHOUT_QUARANTINE = ['available', 'reserved', 'issued', 'expired', 'discarded'];

    public function up(): void
    {
        $this->setAccepted(self::WITH_QUARANTINE);
    }

    /**
     * Narrow the column back, refusing rather than rewriting held stock.
     *
     * A quarantined unit cannot be mapped onto the old values without claiming
     * something untrue: Available says it may be issued, Discarded says it was
     * destroyed.
     */
    public function down(): void
    {
        $held = DB::table('blood_units')->where('status', 'quarantined')->count();

        if ($held > 0) {
            throw new RuntimeException(
                "blood_units holds {$held} quarantined unit(s), which the earlier column cannot express. "
                .'Release or discard them before rolling this back.'
            );
        }

        $this->setAccepted(self::WITHOUT_QUARANTINE);
    }

    /**
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

            DB::statement('ALTER TABLE blood_units DROP CONSTRAINT IF EXISTS blood_units_status_check');
            DB::statement(
                "ALTER TABLE blood_units ADD CONSTRAINT blood_units_status_check CHECK (status IN ({$quoted}))"
            );

            return;
        }

        Schema::table('blood_units', function (Blueprint $table) use ($accepted): void {
            $table->enum('status', $accepted)->default('available')->change();
        });
    }
};
