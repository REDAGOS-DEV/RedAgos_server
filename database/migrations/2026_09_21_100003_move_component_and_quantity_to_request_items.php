<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move the component and quantity off the request and onto its lines.
     *
     * blood_request_items is now the only place that records what a request
     * asked for. Leaving component_id and quantity on the header as well would
     * be a second source of truth for the same fact, which this application
     * refuses elsewhere for inventory and which would drift the first time a
     * line was added to an existing request. The header's quantity becomes an
     * accessor that sums the lines.
     *
     * Unlike the target_facility_id migration, which refused to run against
     * existing rows, there is an honest value to carry here: a request's single
     * component and quantity are exactly the one line it always was. So this
     * backfills rather than guards.
     */
    public function up(): void
    {
        $this->copyHeadersIntoLines();

        Schema::table('blood_requests', function (Blueprint $table): void {
            // Dropped before the column it covers, which cannot go while an
            // index still references it.
            $table->dropIndex(['blood_type_id', 'component_id', 'status']);

            $table->dropConstrainedForeignId('component_id');
            $table->dropColumn('quantity');

            // The component half of the old index now lives on the lines. What
            // is left is still the shape the availability queries read.
            $table->index(['blood_type_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->dropIndex(['blood_type_id', 'status']);

            // Restored nullable, where the original was NOT NULL. A request
            // with no lines — or with several — has no single component to put
            // back, and inventing one to satisfy a constraint would be worse
            // than a nullable column that says plainly it does not know.
            $table->foreignId('component_id')->nullable()->after('blood_type_id')
                ->constrained('blood_components')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(0)->after('component_id');
        });

        $this->copyFirstLineIntoHeaders();

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->index(['blood_type_id', 'component_id', 'status']);
        });
    }

    /**
     * Write each existing request's component and quantity as its first line.
     */
    private function copyHeadersIntoLines(): void
    {
        $now = now();

        DB::table('blood_requests')
            ->select(['id', 'component_id', 'quantity'])
            ->orderBy('id')
            ->chunk(500, function ($requests) use ($now): void {
                $rows = $requests->map(fn ($request): array => [
                    'request_id' => $request->id,
                    'component_id' => $request->component_id,
                    'quantity' => $request->quantity,
                    'indication_code' => null,
                    'indication_other' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('blood_request_items')->insert($rows);
                }
            });
    }

    /**
     * Put each request's first line back on the header, for the reverse migration.
     */
    private function copyFirstLineIntoHeaders(): void
    {
        DB::table('blood_request_items')
            ->select(['request_id', 'component_id', 'quantity'])
            ->orderBy('id')
            ->chunk(500, function ($items): void {
                foreach ($items as $item) {
                    DB::table('blood_requests')
                        ->where('id', $item->request_id)
                        ->whereNull('component_id')
                        ->update([
                            'component_id' => $item->component_id,
                            'quantity' => $item->quantity,
                        ]);
                }
            });
    }
};
