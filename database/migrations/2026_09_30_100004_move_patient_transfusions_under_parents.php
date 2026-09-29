<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Put every existing Patient Transfusion request under a requirement, and retire follow-ups.
     *
     * Until now a patient's need was a blood request addressed to one centre,
     * and whatever that centre could not supply travelled on as a follow-up
     * request chained to it by parent_request_id / parent_item_id. A chain is a
     * requirement with several facility allocations by another name, so each
     * one becomes exactly that: the chain's first request gives the
     * requirement its patient and its per-component quantities, and every
     * request in the chain becomes one of its allocations.
     *
     * Replenishment follow-ups simply lose the link. A restock order has no
     * patient need to chain; the requests remain, independent.
     *
     * Written against the query builder, never the models, so a later change
     * to a model cannot change what this migration does. The requirement's
     * status is left at pending and set afterwards by
     * `php artisan requests:resettle`, which derives it the way the running
     * application does — except where every allocation was refused or
     * withdrawn, which is recorded here as cancelled, with why.
     */
    public function up(): void
    {
        $this->fold();

        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->dropIndex(['parent_item_id']);
            $table->dropConstrainedForeignId('parent_item_id');
        });

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_request_id');
        });
    }

    /**
     * Group every unlinked Patient Transfusion request under a requirement.
     *
     * Public, and safe to run whether or not the follow-up columns still
     * exist, so the grouping can be driven directly by a test.
     */
    public function fold(): void
    {
        DB::transaction(function (): void {
            $chained = Schema::hasColumn('blood_requests', 'parent_request_id');
            $itemsChained = Schema::hasColumn('blood_request_items', 'parent_item_id');

            $requests = DB::table('blood_requests')
                ->where('request_purpose', 'patient_transfusion')
                ->whereNull('transfusion_request_id')
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $groups = [];

            foreach ($requests as $request) {
                $groups[$this->rootOf($request, $requests, $chained)->id][] = $request;
            }

            $sequences = [];

            foreach ($groups as $rootId => $members) {
                $this->moveUnderRequirement($requests[$rootId], $members, $sequences, $itemsChained);
            }

            // The follow-up events are renamed for what they now describe. The
            // note each carries still says what happened in its own words.
            DB::table('blood_request_events')->where('event', 'follow_up_created')->update(['event' => 'submitted']);
            DB::table('blood_request_events')->where('event', 'remainder_forwarded')->update(['event' => 'allocations_added']);
            DB::table('blood_request_events')->where('event', 'follow_up_withdrawn')->update(['event' => 'allocation_withdrawn']);
        });
    }

    /**
     * Refuse to flatten a requirement that has more than one allocation.
     *
     * A single allocation maps back onto a plain request exactly. Several
     * cannot be turned back into a follow-up chain without inventing which one
     * came first and what each carried for which, so this refuses rather than
     * guesses. The renamed follow-up events keep their new names.
     */
    public function down(): void
    {
        $split = DB::table('blood_requests')
            ->whereNotNull('transfusion_request_id')
            ->select('transfusion_request_id')
            ->groupBy('transfusion_request_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($split > 0) {
            throw new RuntimeException(
                "{$split} Patient Transfusion Request(s) have more than one facility allocation and cannot be "
                .'flattened back into single requests. Resolve them before rolling this back.'
            );
        }

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->foreignId('parent_request_id')->nullable()->after('reference_number')
                ->constrained('blood_requests')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->foreignId('parent_item_id')->nullable()->after('request_id')
                ->constrained('blood_request_items')->cascadeOnUpdate()->restrictOnDelete();
            $table->index('parent_item_id');
        });

        DB::transaction(function (): void {
            DB::table('blood_request_events')->whereNull('request_id')->delete();
            DB::table('blood_request_events')->update(['transfusion_request_id' => null]);
            DB::table('blood_request_items')->update(['transfusion_request_item_id' => null]);
            DB::table('blood_requests')->update(['transfusion_request_id' => null]);
            DB::table('transfusion_request_items')->delete();
            DB::table('transfusion_requests')->delete();
        });
    }

    /**
     * Follow a request back to the first request of its follow-up chain.
     *
     * @param  Collection<int, object>  $requests
     */
    private function rootOf(object $request, $requests, bool $chained): object
    {
        $current = $request;

        // Bounded, so a malformed chain cannot loop for ever.
        for ($hops = 0; $hops < 50; $hops++) {
            $parentId = $chained ? ($current->parent_request_id ?? null) : null;

            if ($parentId === null || ! isset($requests[$parentId])) {
                return $current;
            }

            $current = $requests[$parentId];
        }

        return $current;
    }

    /**
     * Create one requirement from a chain's first request, and link the chain to it.
     *
     * @param  array<int, object>  $members
     * @param  array<int, int>  $sequences
     */
    private function moveUnderRequirement(object $root, array $members, array &$sequences, bool $itemsChained): void
    {
        $now = now();
        $active = array_filter($members, fn (object $m): bool => ! in_array($m->status, ['rejected', 'cancelled'], true));
        $allWithdrawn = $active === [] && array_filter($members, fn (object $m): bool => $m->status === 'rejected') === [];

        $requirementId = DB::table('transfusion_requests')->insertGetId([
            'reference_number' => $this->nextReference((int) $root->facility_id, $sequences),
            'facility_id' => $root->facility_id,
            'requested_by' => $root->requested_by,
            'recorded_by' => $root->recorded_by ?? null,
            'request_source' => $root->request_source ?? 'blood_bank_portal',
            'patient_surname' => $root->patient_surname,
            'patient_first_name' => $root->patient_first_name,
            'patient_middle_name' => $root->patient_middle_name,
            'patient_age' => $root->patient_age,
            'patient_sex' => $root->patient_sex,
            'blood_type_id' => $root->blood_type_id,
            'urgency_level' => $root->urgency_level,
            // Every allocation refused or withdrawn is a decision already made;
            // anything else is derived afterwards by requests:resettle.
            'status' => $active === [] ? 'cancelled' : 'pending',
            'cancelled_at' => $active === [] ? $now : null,
            'cancellation_reason' => $active === []
                ? ($allWithdrawn ? 'Withdrawn by the hospital.' : 'Declined by every facility it was sent to.')
                : null,
            'request_date' => $root->request_date,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $byRootItem = [];
        $byComponent = [];

        foreach (DB::table('blood_request_items')->where('request_id', $root->id)->orderBy('id')->get() as $item) {
            $requirementItemId = $this->insertRequirementItem($requirementId, $item, $now);
            $byRootItem[$item->id] = $requirementItemId;
            $byComponent[$item->component_id] = $requirementItemId;
        }

        foreach ($members as $member) {
            DB::table('blood_requests')->where('id', $member->id)->update(['transfusion_request_id' => $requirementId]);

            foreach (DB::table('blood_request_items')->where('request_id', $member->id)->get() as $item) {
                $requirementItemId = $member->id === $root->id
                    ? $byRootItem[$item->id]
                    : ($this->requirementItemFor($item, $byRootItem, $itemsChained) ?? $byComponent[$item->component_id] ?? null);

                // A follow-up line for a component the first request never
                // asked for: the requirement gains that line, at what was asked.
                if ($requirementItemId === null) {
                    $requirementItemId = $this->insertRequirementItem($requirementId, $item, $now);
                    $byComponent[$item->component_id] = $requirementItemId;
                }

                DB::table('blood_request_items')->where('id', $item->id)
                    ->update(['transfusion_request_item_id' => $requirementItemId]);
            }

            DB::table('blood_request_events')->where('request_id', $member->id)
                ->update(['transfusion_request_id' => $requirementId]);
        }
    }

    private function insertRequirementItem(int $requirementId, object $item, $now): int
    {
        return DB::table('transfusion_request_items')->insertGetId([
            'transfusion_request_id' => $requirementId,
            'component_id' => $item->component_id,
            'quantity' => $item->quantity,
            'indication_code' => $item->indication_code,
            'indication_other' => $item->indication_other,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Follow a follow-up line back to the first request's line it carried.
     *
     * @param  array<int, int>  $byRootItem
     */
    private function requirementItemFor(object $item, array $byRootItem, bool $itemsChained): ?int
    {
        if (! $itemsChained) {
            return null;
        }

        $current = $item;

        for ($hops = 0; $hops < 50; $hops++) {
            $parentItemId = $current->parent_item_id ?? null;

            if ($parentItemId === null) {
                return null;
            }

            if (isset($byRootItem[$parentItemId])) {
                return $byRootItem[$parentItemId];
            }

            $current = DB::table('blood_request_items')->where('id', $parentItemId)->first();

            if ($current === null) {
                return null;
            }
        }

        return null;
    }

    /**
     * The next PTR-{hospital}-NNNN, parsed the way the application parses it.
     *
     * @param  array<int, int>  $sequences
     */
    private function nextReference(int $facilityId, array &$sequences): string
    {
        $prefix = "PTR-{$facilityId}-";

        if (! isset($sequences[$facilityId])) {
            $highest = 0;

            foreach (DB::table('transfusion_requests')->where('reference_number', 'like', $prefix.'%')->pluck('reference_number') as $reference) {
                $suffix = substr($reference, strlen($prefix));

                if (ctype_digit($suffix)) {
                    $highest = max($highest, (int) $suffix);
                }
            }

            $sequences[$facilityId] = $highest;
        }

        $sequences[$facilityId]++;

        return $prefix.str_pad((string) $sequences[$facilityId], 4, '0', STR_PAD_LEFT);
    }
};
