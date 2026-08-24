 <?php

 use App\Enums\BloodUnitStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reassert blood_units.status from BloodUnitStatus on databases created
     * before the enum existed.
     *
     * create_blood_units_table now builds the column from the same enum, so a
     * fresh database is already correct and this is a redundant no-op there.
     * It exists for already-migrated instances.
     *
     * $table->enum() compiles differently per driver: a native ENUM on MySQL, a
     * varchar plus a CHECK constraint on PostgreSQL, and an unenforced varchar
     * on sqlite.
     */
    public function up(): void
    {
        $connection = Schema::getConnection();
        $pdo = $connection->getPdo();

        // A DDL statement cannot take query bindings, so the values are quoted
        // through the connection's own PDO rather than concatenated raw. They
        // originate in a PHP enum and are not attacker-controlled, but the
        // quoting still has to be real.
        $quoted = implode(', ', array_map(
            fn (string $value): string => $pdo->quote($value),
            BloodUnitStatus::values()
        ));

        $default = $pdo->quote(BloodUnitStatus::Available->value);

        match ($connection->getDriverName()) {
            'pgsql' => DB::statement(
                'ALTER TABLE blood_units DROP CONSTRAINT IF EXISTS blood_units_status_check, '
                ."ADD CONSTRAINT blood_units_status_check CHECK (status IN ({$quoted}))"
            ),
            'mysql', 'mariadb' => DB::statement(
                "ALTER TABLE blood_units MODIFY status ENUM({$quoted}) NOT NULL DEFAULT {$default}"
            ),
            default => null,
        };
    }

    /**
     * Intentionally a no-op. Narrowing the constraint back to an older list
     * would fail against any row already holding a value that list lacks.
     */
    public function down(): void
    {
        //
    }
};
