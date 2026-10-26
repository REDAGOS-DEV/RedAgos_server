<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cashier's shift at the billing counter: the cash drawer from its float to its count.
     *
     * Opened with the float in the drawer, it collects the counter's payments,
     * and is closed with the cash counted. What the drawer should hold is
     * worked out at close from the shift's own transactions and frozen here
     * beside the count, with the difference.
     *
     * Two rules held in the database, PostgreSQL and SQLite, as the payments
     * ledger's are:
     *  - one open shift per cashier, by a partial unique index;
     *  - a closed shift never changes, and no shift is ever deleted.
     * The models refuse the same earlier.
     *
     * Values are literals rather than application enums, so this migration
     * never depends on later application code.
     */
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('session_number', 40);
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('counter_label', 40)->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->decimal('opening_float', 12, 2);
            $table->timestamp('opened_at');
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->json('count_breakdown')->nullable();
            $table->string('closing_note', 500)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['facility_id', 'session_number']);
            $table->index(['facility_id', 'opened_at']);
            $table->index(['cashier_id', 'status']);
        });

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->createGuardsOnPostgres(),
            'sqlite' => $this->createGuardsOnSqlite(),
            default => null,
        };
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS cash_sessions_guard ON cash_sessions');
            DB::unprepared('DROP FUNCTION IF EXISTS cash_sessions_guard()');
        }

        // The partial index and SQLite's triggers go with the table.
        Schema::dropIfExists('cash_sessions');
    }

    private function createGuardsOnPostgres(): void
    {
        DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open_per_cashier ON cash_sessions (cashier_id) WHERE status = 'open'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_sessions_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'cash_sessions: a cash shift cannot be deleted'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'closed' THEN
                    RAISE EXCEPTION 'cash_sessions: a closed cash shift cannot be changed'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cash_sessions_guard
                BEFORE UPDATE OR DELETE ON cash_sessions
                FOR EACH ROW EXECUTE FUNCTION cash_sessions_guard()
            SQL);
    }

    private function createGuardsOnSqlite(): void
    {
        DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open_per_cashier ON cash_sessions (cashier_id) WHERE status = 'open'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cash_sessions_no_delete
            BEFORE DELETE ON cash_sessions
            BEGIN
                SELECT RAISE(ABORT, 'cash_sessions: a cash shift cannot be deleted');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cash_sessions_closed_is_final
            BEFORE UPDATE ON cash_sessions
            WHEN OLD.status = 'closed'
            BEGIN
                SELECT RAISE(ABORT, 'cash_sessions: a closed cash shift cannot be changed');
            END
            SQL);
    }
};
