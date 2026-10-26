<?php

namespace App\Repository;

use Illuminate\Support\Facades\DB;

/**
 * The per-facility counters behind statement and receipt numbers.
 */
class DocumentSequenceRepository
{
    /**
     * Take the next number of one kind for one facility.
     *
     * Must run inside the issuing transaction, and should be its last lock:
     * the counter row stays locked until that transaction ends, so concurrent
     * issuers at the same facility take turns, and a rollback returns the
     * number. The row is created on first use; insertOrIgnore lets two first
     * uses race without either failing.
     */
    public function next(int $facilityId, string $kind): int
    {
        DB::table('facility_document_sequences')->insertOrIgnore([
            'facility_id' => $facilityId,
            'kind' => $kind,
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = DB::table('facility_document_sequences')
            ->where('facility_id', $facilityId)
            ->where('kind', $kind)
            ->lockForUpdate()
            ->first();

        DB::table('facility_document_sequences')
            ->where('id', $counter->id)
            ->update(['next_value' => $counter->next_value + 1, 'updated_at' => now()]);

        return (int) $counter->next_value;
    }
}
