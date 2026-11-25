<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The unit price a request line was billed at (project owner, 2026-11-26).
     *
     * A centre changing its price must not change a bill already raised: that
     * bill is a past transaction, made at the old price. A line's price is
     * locked the first time it goes on the bill (StatementFigures::lockPrices)
     * and every figure read from the bill uses it after that. Lines that have
     * not been billed yet are left empty, and take the price of the day they
     * are first billed.
     *
     * Lines already on a bill are locked here: at the price their latest
     * issued invoice showed, which is what the payer was given, or else at the
     * centre's present price, which is what the bill is showing now.
     */
    public function up(): void
    {
        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->decimal('billed_unit_price', 10, 2)->nullable();
            $table->timestamp('priced_at')->nullable();
        });

        $billed = DB::table('blood_request_items as item')
            ->join('billings as billing', 'billing.request_id', '=', 'item.request_id')
            ->join('blood_requests as request', 'request.id', '=', 'item.request_id')
            ->whereExists(fn ($held) => $held->from('request_allocations as allocation')
                ->whereColumn('allocation.request_item_id', 'item.id')
                ->whereIn('allocation.status', ['allocated', 'released']))
            ->select('item.id', 'item.component_id', 'request.target_facility_id', 'billing.billing_date')
            ->get();

        foreach ($billed as $item) {
            $invoiced = DB::table('billing_revision_items as line')
                ->join('billing_revisions as revision', 'revision.id', '=', 'line.billing_revision_id')
                ->where('line.request_item_id', $item->id)
                ->orderByDesc('revision.revision_number')
                ->value('line.unit_price');

            $current = $item->target_facility_id === null ? null : DB::table('facility_blood_components')
                ->where('facility_id', $item->target_facility_id)
                ->where('component_id', $item->component_id)
                ->value('price');

            DB::table('blood_request_items')->where('id', $item->id)->update([
                'billed_unit_price' => $invoiced ?? $current ?? 0,
                'priced_at' => $item->billing_date ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->dropColumn(['billed_unit_price', 'priced_at']);
        });
    }
};
