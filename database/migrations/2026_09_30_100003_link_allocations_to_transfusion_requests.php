<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make a blood request the facility allocation of a Patient Transfusion Request.
     *
     * A blood_requests row stays exactly what it was to the centre it is
     * addressed to — its queue, its holds, its release and receipt — and now
     * also says which patient requirement it is a share of. Replenishment rows
     * leave both columns null.
     *
     * restrictOnDelete: a requirement with allocations against it is part of a
     * patient's history and is never deleted out from under them.
     *
     * Events may now belong to the requirement rather than to one allocation —
     * "created", "allocations added", "remaining closed", "cancelled" — so
     * blood_request_events.request_id becomes nullable and every event can name
     * the requirement it concerns.
     */
    public function up(): void
    {
        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->foreignId('transfusion_request_id')->nullable()->after('reference_number')
                ->constrained('transfusion_requests')->cascadeOnUpdate()->restrictOnDelete();

            $table->index(['transfusion_request_id', 'status']);
        });

        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->foreignId('transfusion_request_item_id')->nullable()->after('request_id')
                ->constrained('transfusion_request_items')->cascadeOnUpdate()->restrictOnDelete();

            $table->index('transfusion_request_item_id');
        });

        Schema::table('blood_request_events', function (Blueprint $table): void {
            $table->foreignId('transfusion_request_id')->nullable()->after('request_id')
                ->constrained('transfusion_requests')->cascadeOnUpdate()->restrictOnDelete();

            $table->index(['transfusion_request_id', 'created_at']);
        });

        // A separate call, so the column change is not folded into the table
        // rebuild SQLite performs for the foreign key above.
        Schema::table('blood_request_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('request_id')->nullable()->change();
        });
    }

    /**
     * Refuse to narrow request_id back while requirement-level events exist.
     */
    public function down(): void
    {
        $orphaned = DB::table('blood_request_events')->whereNull('request_id')->count();

        if ($orphaned > 0) {
            throw new RuntimeException(
                "blood_request_events holds {$orphaned} event(s) that belong to no single blood request. "
                .'Remove them before rolling this back.'
            );
        }

        Schema::table('blood_request_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('request_id')->nullable(false)->change();
        });

        Schema::table('blood_request_events', function (Blueprint $table): void {
            $table->dropIndex(['transfusion_request_id', 'created_at']);
            $table->dropConstrainedForeignId('transfusion_request_id');
        });

        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->dropIndex(['transfusion_request_item_id']);
            $table->dropConstrainedForeignId('transfusion_request_item_id');
        });

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->dropIndex(['transfusion_request_id', 'status']);
            $table->dropConstrainedForeignId('transfusion_request_id');
        });
    }
};
