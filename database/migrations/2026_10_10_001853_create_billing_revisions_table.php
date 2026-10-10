<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable snapshots of a statement, one per issued Statement of Account.
     *
     * The billings row is a live draft: it grows as units are reserved and is
     * what the release gate reads. A document a payer has seen must never
     * change, so every issued statement, checkout amount and payment is pinned
     * to a revision frozen at that moment: its lines, its total, what had been
     * collected, and what was due.
     *
     * Neither table can be updated or deleted once written. That is held by
     * triggers in the database itself, so a query-builder update, a script or a
     * direct SQL session is bound by it too; the models refuse the same earlier.
     * PostgreSQL and SQLite only, as with the payments ledger triggers.
     *
     * Values are literals rather than application enums, so this migration
     * never depends on later application code.
     */
    public function up(): void
    {
        Schema::create('billing_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_id')->constrained('billings')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('document_number', 40);
            $table->foreignId('issuing_facility_id')->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('payer_facility_id')->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->char('currency', 3)->default('PHP');
            $table->decimal('total_amount', 10, 2);
            $table->decimal('collected_at_issue', 10, 2);
            $table->decimal('amount_due', 10, 2);
            $table->boolean('statement_only')->default(false);
            // The statement's status at issue, so a printed statement can say
            // it was subsidised or statement-only without reading the live row.
            $table->string('billing_status', 20);
            $table->enum('reason', ['statement', 'checkout', 'payment']);
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['billing_id', 'revision_number']);
            $table->unique(['issuing_facility_id', 'document_number']);
        });

        Schema::create('billing_revision_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_revision_id')->constrained('billing_revisions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('request_item_id')->nullable()->constrained('blood_request_items')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('component_id')->constrained('blood_components')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('component_name', 120);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 12, 2);

            $table->index('billing_revision_id');
        });

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->createTriggersOnPostgres(),
            'sqlite' => $this->createTriggersOnSqlite(),
            default => null,
        };
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS billing_revision_items_immutable ON billing_revision_items');
            DB::unprepared('DROP TRIGGER IF EXISTS billing_revisions_immutable ON billing_revisions');
            DB::unprepared('DROP FUNCTION IF EXISTS billing_revisions_refuse_change()');
        }

        // SQLite's triggers go with their tables.
        Schema::dropIfExists('billing_revision_items');
        Schema::dropIfExists('billing_revisions');
    }

    private function createTriggersOnPostgres(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION billing_revisions_refuse_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '%: an issued statement revision cannot be changed or deleted', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER billing_revisions_immutable
                BEFORE UPDATE OR DELETE ON billing_revisions
                FOR EACH ROW EXECUTE FUNCTION billing_revisions_refuse_change()
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER billing_revision_items_immutable
                BEFORE UPDATE OR DELETE ON billing_revision_items
                FOR EACH ROW EXECUTE FUNCTION billing_revisions_refuse_change()
            SQL);
    }

    private function createTriggersOnSqlite(): void
    {
        foreach (['billing_revisions', 'billing_revision_items'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = $table.'_no_'.strtolower($operation);

                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$name}
                    BEFORE {$operation} ON {$table}
                    BEGIN
                        SELECT RAISE(ABORT, '{$table}: an issued statement revision cannot be changed or deleted');
                    END
                    SQL);
            }
        }
    }
};
