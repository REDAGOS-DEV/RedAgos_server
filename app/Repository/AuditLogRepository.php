<?php

namespace App\Repository;

use App\Models\AuditLog;
use App\Models\BloodRequest;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonorDeferral;
use App\Models\PaperDonationSheet;
use App\Models\User;
use App\Support\AuditActions;
use App\Support\OperationalDay;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * One centre's audit trail as the Audit Log page shows it: what its own staff
 * did, and what ran on its behalf with nobody signed in (the expiry sweep, a
 * payment webhook, a refused sign-in on one of its accounts).
 */
class AuditLogRepository
{
    /**
     * Filters: search (a staff member's name or employee ID, an action's
     * wording, or a record's reference), module and type (AuditActions keys),
     * from and to (operational dates, inclusive). Newest first.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(int $facilityId, array $filters): Builder
    {
        return AuditLog::query()
            // A removed colleague's rows still say who wrote them.
            ->with(['actor' => fn ($actor) => $actor->withTrashed()])
            ->where('facility_id', $facilityId)
            // The centre's own staff, or nobody. A donor's or the Super
            // Admin's row filed here by its context is not the staff's doing.
            ->where(fn (Builder $q) => $q
                ->whereNull('actor_id')
                ->orWhereIn('actor_id', $this->staffIds($facilityId)))
            ->when(isset($filters['module']), fn (Builder $q) => $q->whereIn('action', AuditActions::inModule($filters['module'])))
            ->when(isset($filters['type']), fn (Builder $q) => $q->whereIn('action', AuditActions::ofType($filters['type'])))
            ->when(isset($filters['from']), fn (Builder $q) => $q->where('created_at', '>=', OperationalDay::boundsFor($filters['from'])[0]))
            ->when(isset($filters['to']), fn (Builder $q) => $q->where('created_at', '<=', OperationalDay::boundsFor($filters['to'])[1]))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $this->applySearch($q, $facilityId, (string) $filters['search']))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query($facilityId, $filters)->paginate($perPage);
    }

    /**
     * The accounts some rows are about (a colleague whose role changed, the
     * account a sign-in was refused on), removed ones included.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, User>
     */
    public function usersByIds(array $ids): Collection
    {
        return $ids === []
            ? new Collection
            : User::withTrashed()->whereKey($ids)->get()->keyBy('id');
    }

    /**
     * Requests' reference numbers by id, for rows whose context names a
     * request only by its id.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    public function requestReferences(array $ids): array
    {
        return $ids === []
            ? []
            : BloodRequest::query()->whereKey($ids)->pluck('reference_number', 'id')->all();
    }

    private function staffIds(int $facilityId): Builder
    {
        return User::withTrashed()->select('id')->where('facility_id', $facilityId);
    }

    private function applySearch(Builder $query, int $facilityId, string $term): Builder
    {
        $term = trim($term);
        $like = '%'.mb_strtolower($term).'%';
        $actions = AuditActions::matching($term);

        // A colleague the row is about, not only the one who wrote it: "Maria"
        // finds the day her roles were changed. auditable_id is a string
        // column, so the ids go in as strings rather than as a subquery.
        $subjects = User::withTrashed()
            ->where('facility_id', $facilityId)
            ->where(fn (Builder $user) => $this->matchName($user, $like))
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->all();

        return $query->where(function (Builder $inner) use ($like, $actions, $subjects, $term): void {
            $inner->whereHas('actor', fn (Builder $actor) => $this->matchName($actor->withTrashed(), $like))
                ->orWhereRaw('LOWER(auditable_id) LIKE ?', [$like])
                ->when($actions !== [], fn (Builder $q) => $q->orWhereIn('action', $actions))
                ->when($subjects !== [], fn (Builder $q) => $q->orWhere(fn (Builder $subject) => $subject
                    ->where('auditable_type', User::class)
                    ->whereIn('auditable_id', $subjects)))
                // A reference (RQ-4-0007, AR-1-000010, a DON- code) lives in
                // the context. Only a term with a digit in it is looked for
                // there, so a word does not match the context's key names.
                ->when(preg_match('/\d/', $term) === 1, fn (Builder $q) => $q->orWhereRaw('LOWER(CAST(context AS TEXT)) LIKE ?', [$like]));

            $code = $this->codedRecord($term);

            if ($code !== null) {
                $inner->orWhere(fn (Builder $q) => $q->where('auditable_type', $code[0])->where('auditable_id', $code[1]));
            }
        });
    }

    private function matchName(Builder $user, string $like): Builder
    {
        return $user->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(first_name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
            ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])
            ->orWhereRaw('LOWER(employee_id) LIKE ?', [$like]));
    }

    /**
     * A DON-, APT-, DEF-, PS- or DONOR- code, as the Record column prints it,
     * read back to the subject it names.
     *
     * @return array{0: class-string, 1: string}|null
     */
    private function codedRecord(string $term): ?array
    {
        if (preg_match('/^(DONOR|DON|APT|DEF|PS)-?(\d{1,10})$/i', $term, $m) !== 1) {
            return null;
        }

        $type = match (strtoupper($m[1])) {
            'DONOR' => User::class,
            'DON' => Donation::class,
            'APT' => DonationAppointment::class,
            'DEF' => DonorDeferral::class,
            'PS' => PaperDonationSheet::class,
        };

        return [$type, (string) (int) $m[2]];
    }
}
