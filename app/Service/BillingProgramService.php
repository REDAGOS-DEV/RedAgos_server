<?php

namespace App\Service;

use App\Models\BillingProgram;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * The Billing Supervisor's dated programme register. Its dates are checked
 * when a subsidy is applied, never trusted from a browser's current time.
 */
class BillingProgramService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @return array{data: array<int, array<string, mixed>>} */
    public function index(User $staff): array
    {
        return [
            'data' => BillingProgram::query()
                ->where('facility_id', $this->facilityId($staff))
                ->orderByDesc('is_enabled')
                ->orderBy('effective_from')
                ->orderBy('id')
                ->get()
                ->map(fn (BillingProgram $program): array => $this->format($program))
                ->all(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function create(User $staff, array $payload): array
    {
        $program = BillingProgram::query()->create([
            ...$payload,
            'facility_id' => $this->facilityId($staff),
            'value' => $this->valueFor($payload),
            'is_enabled' => $payload['is_enabled'] ?? true,
            'created_by' => $staff->id,
            'updated_by' => $staff->id,
        ]);

        $this->auditLogger->record($staff, 'billing.program_created', $program, $this->auditContext($program));

        return ['message' => "{$program->name} is scheduled.", 'data' => $this->format($program)];
    }

    /** @param array<string, mixed> $payload */
    public function update(User $staff, int $programId, array $payload): array
    {
        $program = $this->forFacility($staff, $programId);
        $before = $this->format($program);

        $program->fill([
            ...$payload,
            'value' => $this->valueFor($payload),
            'updated_by' => $staff->id,
        ])->save();

        $this->auditLogger->record($staff, 'billing.program_updated', $program, [
            'before' => $before,
            'after' => $this->format($program),
        ]);

        return ['message' => "{$program->name} updated.", 'data' => $this->format($program)];
    }

    /** Whether the centre has begun managing government-subsidy availability. */
    public function subsidyIsConfiguredFor(int $facilityId): bool
    {
        return BillingProgram::query()
            ->where('facility_id', $facilityId)
            ->where('kind', BillingProgram::GOVERNMENT_SUBSIDY)
            ->exists();
    }

    /** The one programme valid for today, if the programme register is in use. */
    public function activeSubsidyFor(int $facilityId): ?BillingProgram
    {
        $today = OperationalDay::today()->toDateString();

        return BillingProgram::query()
            ->where('facility_id', $facilityId)
            ->where('kind', BillingProgram::GOVERNMENT_SUBSIDY)
            ->where('is_enabled', true)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @return array<string, mixed> */
    private function format(BillingProgram $program): array
    {
        $today = OperationalDay::today()->toDateString();
        $starts = $program->effective_from?->toDateString();
        $ends = $program->effective_to?->toDateString();
        $activeNow = $program->is_enabled && $starts <= $today && ($ends === null || $ends >= $today);

        return [
            'id' => $program->id,
            'name' => $program->name,
            'kind' => $program->kind,
            'value' => $program->value === null ? null : (float) $program->value,
            'effective_from' => $starts,
            'effective_to' => $ends,
            'is_enabled' => $program->is_enabled,
            'is_active_now' => $activeNow,
            'notes' => $program->notes,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function valueFor(array $payload): ?float
    {
        if (($payload['kind'] ?? null) === BillingProgram::GOVERNMENT_SUBSIDY) {
            return null;
        }

        return (float) $payload['value'];
    }

    private function forFacility(User $staff, int $programId): BillingProgram
    {
        return BillingProgram::query()
            ->where('facility_id', $this->facilityId($staff))
            ->whereKey($programId)
            ->first()
            ?? throw $this->refuse(404, 'billing_program_not_found', 'That billing programme was not found.');
    }

    private function facilityId(User $staff): int
    {
        return $staff->facility_id
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    /** @return array<string, mixed> */
    private function auditContext(BillingProgram $program): array
    {
        return ['facility_id' => $program->facility_id, 'program' => $this->format($program)];
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['code' => $code, 'message' => $message], $status));
    }
}
