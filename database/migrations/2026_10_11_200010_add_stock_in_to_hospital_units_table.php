<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The statuses after this migration, and before it.
     *
     * Written out rather than read from HospitalUnitStatus, so this migration
     * keeps meaning the same thing whatever the enum later becomes.
     */
    private const ACCEPTED = ['received', 'available', 'tag_assigned', 'tag_crossmatched', 'pending_return', 'transfused', 'expired', 'discarded'];

    private const PREVIOUS = ['available', 'tag_assigned', 'tag_crossmatched', 'pending_return', 'transfused', 'expired', 'discarded'];

    /**
     * Receiving becomes two steps: a bag arrives, then inventory staff stock it in.
     *
     * Decided by the project owner on 2026-10-11. A bag whose arrival was
     * confirmed, or that came from outside RedAgos, is `received`: in the
     * hospital's custody but not stock. Stocking it in makes it `available`
     * and records where it is stored (optional, as at a centre's Stock
     * Intake), when, and by whom.
     *
     * Bags already in stock stay as they are, with no location: they were
     * shelved before there was anywhere to say where.
     *
     * PostgreSQL swaps the status CHECK constraint; SQLite rebuilds the
     * column, as in add_cash_shift_and_void_to_payments_table. The table has
     * no triggers to restore.
     */
    public function up(): void
    {
        $this->setAccepted(self::ACCEPTED);

        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->string('storage_location', 100)->nullable()->after('status');
            $table->timestamp('stocked_at')->nullable()->after('storage_location');
        });

        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->foreignId('stocked_by')->nullable()->after('stocked_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->index(['facility_id', 'storage_location']);
        });
    }

    /**
     * Refused while any bag is awaiting stock-in, which the earlier table cannot say.
     */
    public function down(): void
    {
        $waiting = DB::table('hospital_units')->where('status', 'received')->count();

        if ($waiting > 0) {
            throw new RuntimeException(
                "hospital_units holds {$waiting} bag(s) awaiting stock-in, which the earlier column cannot express. "
                .'Stock them in or discard them before rolling this back.'
            );
        }

        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->dropIndex(['facility_id', 'storage_location']);
            $table->dropForeign(['stocked_by']);
        });

        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->dropColumn(['storage_location', 'stocked_at', 'stocked_by']);
        });

        $this->setAccepted(self::PREVIOUS);
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

            DB::statement('ALTER TABLE hospital_units DROP CONSTRAINT IF EXISTS hospital_units_status_check');
            DB::statement("ALTER TABLE hospital_units ADD CONSTRAINT hospital_units_status_check CHECK (status IN ({$quoted}))");

            return;
        }

        Schema::table('hospital_units', function (Blueprint $table) use ($accepted): void {
            $table->enum('status', $accepted)->default('available')->change();
        });
    }
};
