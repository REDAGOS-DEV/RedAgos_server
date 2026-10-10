<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let one blood request carry lines of several blood types.
     *
     * A weekly request restocks several blood types at once, and a request
     * held a single one, so each weekly request was written as one request per
     * blood type: the centre reviewed, reserved, billed and dispatched each on
     * its own. The blood type now lives on the line too, so a weekly request is
     * one request whose lines each name their own type.
     *
     * Every existing line takes its request's type. blood_requests.blood_type_id
     * keeps meaning what it meant on every request but a mixed one — the
     * patient's type on a transfusion, the one type of a restock — and is null
     * only on a request whose lines differ.
     *
     * A component may now appear once per blood type rather than once per
     * request: A+ and O+ packed cells are two lines of one weekly request.
     */
    public function up(): void
    {
        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->foreignId('blood_type_id')->nullable()->after('component_id')
                ->constrained('blood_types')->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::table('blood_request_items')->update([
            'blood_type_id' => DB::raw(
                '(SELECT blood_requests.blood_type_id FROM blood_requests WHERE blood_requests.id = blood_request_items.request_id)'
            ),
        ]);

        // A separate call, so the column change is not folded into the table
        // rebuild SQLite performs for the foreign key above.
        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('blood_type_id')->nullable(false)->change();
        });

        // The new key is added before the old one goes, so request_id is
        // never left without an index leading on it.
        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->unique(['request_id', 'blood_type_id', 'component_id']);
        });

        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->dropUnique(['request_id', 'component_id']);
        });

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('blood_type_id')->nullable()->change();
        });
    }

    /**
     * Refuse to narrow the request back to one blood type while a request has several.
     */
    public function down(): void
    {
        $mixed = DB::table('blood_requests')->whereNull('blood_type_id')->count();

        if ($mixed > 0) {
            throw new RuntimeException(
                "blood_requests holds {$mixed} request(s) whose lines are of several blood types. "
                .'Remove them before rolling this back.'
            );
        }

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('blood_type_id')->nullable(false)->change();
        });

        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->unique(['request_id', 'component_id']);
        });

        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->dropUnique(['request_id', 'blood_type_id', 'component_id']);
        });

        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('blood_type_id');
        });
    }
};
