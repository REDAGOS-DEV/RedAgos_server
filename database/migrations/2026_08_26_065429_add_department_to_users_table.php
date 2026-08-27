<?php

use App\Enums\FacilityStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split blood-centre staff into departments and mark the management level.
     *
     * The two columns are orthogonal. department says which of the four
     * operational areas a staff member works in; is_supervisor says whether
     * they hold the management level. A supervisor may carry a department (a
     * working supervisor) or none at all, and holds the full permission set
     * either way.
     *
     * department stays nullable because a management-only supervisor
     * legitimately has none. "A non-supervisor must have a department" is a
     * validation rule on the staff endpoints, not a database constraint,
     * because backfilled staff sit at null until a supervisor assigns them.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('department', 40)->nullable()->after('position');
            $table->boolean('is_supervisor')->default(false)->after('department');

            // Leading column is facility_id, so this serves the roster query
            // ("everyone at my facility") as well as the narrower
            // ("everyone in my facility's laboratory").
            $table->index(['facility_id', 'department']);
        });

        $this->backfillSupervisors();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['facility_id', 'department']);
            $table->dropColumn(['department', 'is_supervisor']);
        });
    }

    /**
     * Ensure every facility that has staff also has someone who can manage them.
     *
     * Marking only registration_contact_user_id is not enough: a facility
     * seeded or created before that column existed can have it null while still
     * carrying staff, which would leave the centre with nobody able to assign
     * departments. Three cases, handled separately.
     *
     * Public so SupervisorBackfillTest can drive it against each shape without
     * tearing the schema down and rebuilding it.
     */
    public function backfillSupervisors(): void
    {
        $stranded = [];

        // Facility counts are small and this runs once, so the whole set is
        // read up front rather than chunked — the loop writes back to
        // facilities, and offset chunking over a table being written to is a
        // subtlety worth not having.
        $facilities = DB::table('facilities')->whereNull('deleted_at')->orderBy('id')->get();

        foreach ($facilities as $facility) {
            // Idempotence guard. Checked by existence rather than by an
            // affected-row count, because MySQL reports zero rows changed when
            // a column is already set to the value being written, which would
            // read as "the promotion failed" and promote a second person.
            if ($this->hasSupervisor((int) $facility->id)) {
                continue;
            }

            $target = $this->resolveSupervisor($facility);

            if ($target === null) {
                // No staff at all. Nothing to strand, so this is reported
                // rather than treated as a failure — the seeded Davao centres
                // donors book against are exactly this shape.
                if ($facility->status === FacilityStatus::Approved->value) {
                    $stranded[] = "{$facility->id} ({$facility->name})";
                }

                continue;
            }

            DB::table('users')->where('id', $target)->update(['is_supervisor' => true]);

            // Write the fallback choice back so it is inspectable later. Guarded
            // on null so a real registration contact is never overwritten.
            DB::table('facilities')
                ->where('id', $facility->id)
                ->whereNull('registration_contact_user_id')
                ->update(['registration_contact_user_id' => $target]);
        }

        if ($stranded !== []) {
            Log::warning(
                'Approved facilities have no staff and therefore no supervisor: '
                .implode(', ', $stranded)
                .'. The first account registered against each will need promoting.'
            );
        }
    }

    /**
     * Determine whether a facility already has someone holding the management level.
     */
    private function hasSupervisor(int $facilityId): bool
    {
        return DB::table('users')
            ->where('facility_id', $facilityId)
            ->where('is_supervisor', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Pick the user to promote: the registration contact, else the longest-standing staff member.
     *
     * The contact is re-checked against the facility so a stale id pointing at
     * someone who has since moved or been deleted falls through to the
     * deterministic fallback rather than promoting nobody. Ordering by id makes
     * the fallback reproducible.
     */
    private function resolveSupervisor(object $facility): ?int
    {
        $contactId = $facility->registration_contact_user_id;

        if ($contactId !== null) {
            $contact = DB::table('users')
                ->where('id', $contactId)
                ->where('facility_id', $facility->id)
                ->whereNull('deleted_at')
                ->value('id');

            if ($contact !== null) {
                return (int) $contact;
            }
        }

        $fallback = DB::table('users')
            ->where('facility_id', $facility->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        return $fallback === null ? null : (int) $fallback;
    }
};
