<?php

namespace App\Service;

use App\Models\DeferralCode;
use App\Models\Facility;
use App\Models\User;
use App\Repository\DeferralCodeRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * A blood centre's deferral codes (project owner, 2026-10-24).
 *
 * A deferral is recorded by code, and the record shows the code: a donor who
 * glimpses the counter's screen, a printed form or their own deferral email
 * sees "R1", never the reason. The reason stays with staff allowed the
 * clinical record (donors.view_clinical), and the Screening Physician keeps
 * the list (deferrals.configure).
 *
 * Each centre has its own list, as it keeps its own deferral records. A
 * centre's first look seeds it with the reasons the Deferrals page offered
 * before codes existed (DeferralCode::DEFAULTS), so recording works from the
 * first day.
 *
 * A code is never deleted and, once a record carries it, never renamed: a
 * donor told "R1" must be able to ask about R1 later. Its wording can change;
 * records copied it when they were written, so they keep theirs.
 *
 * "Other" stays beside the codes: the reason typed, no code. The record keeps
 * the words behind the same gate, and the donor's notice carries nothing.
 */
class DeferralCodeService
{
    public function __construct(
        private readonly DeferralCodeRepository $deferralCodes,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * The centre's codes. The reasons only for a viewer allowed them;
     * everyone else who records a deferral (a drive's paper record) picks by
     * code alone.
     *
     * @return array<string, mixed>
     */
    public function index(User $staff, bool $withRetired): array
    {
        $facility = $this->requireFacility($staff);
        $this->ensureDefaults($facility);

        $seesReasons = $this->seesReasons($staff);
        $usage = $seesReasons ? $this->deferralCodes->usage($facility->id) : [];

        return [
            'data' => $this->deferralCodes->forFacility($facility->id, $withRetired)
                ->map(fn (DeferralCode $code): array => $this->format($code, $seesReasons, $usage))
                ->all(),
            'can' => [
                'view_reasons' => $seesReasons,
                'manage' => $staff->can('deferrals.configure'),
            ],
        ];
    }

    /**
     * @param  array{code: string, reason: string}  $payload
     * @return array<string, mixed>
     */
    public function create(User $staff, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $this->ensureDefaults($facility);

        $code = DeferralCode::create([
            'facility_id' => $facility->id,
            'code' => $payload['code'],
            'reason' => $payload['reason'],
            'created_by' => $staff->id,
            'updated_by' => $staff->id,
        ]);

        // The code, never the reason: the audit trail must not copy clinical text.
        $this->auditLogger->record($staff, 'center.deferral_code_added', $code, [
            'facility_id' => $facility->id,
            'code' => $code->code,
        ]);

        return [
            'message' => "{$code->code} added.",
            'data' => $this->format($code->load('updater'), true, []),
        ];
    }

    /**
     * Change a code's wording, retire it or bring it back, or correct the code
     * itself while no record carries it yet.
     *
     * @param  array{code?: string, reason?: string, retired?: bool}  $payload
     * @return array<string, mixed>
     */
    public function update(User $staff, int $id, array $payload): array
    {
        $facility = $this->requireFacility($staff);

        $code = $this->deferralCodes->find($facility->id, $id)
            ?? throw $this->refuse(404, 'deferral_code_not_found', 'This centre has no such deferral code.');

        $before = $code->code;

        if (isset($payload['code']) && $payload['code'] !== $code->code) {
            if ($this->deferralCodes->inUse($facility->id, $code->code)) {
                throw ValidationException::withMessages([
                    'code' => "{$code->code} is already on a donor's record, so it keeps its code. Retire it and add a new one instead.",
                ]);
            }

            $code->code = $payload['code'];
        }

        if (isset($payload['reason'])) {
            $code->reason = $payload['reason'];
        }

        if (array_key_exists('retired', $payload)) {
            $code->retired_at = $payload['retired'] ? ($code->retired_at ?? now()) : null;
        }

        if (! $code->isDirty()) {
            return [
                'message' => 'Nothing to change.',
                'data' => $this->format($code->load('updater'), true, $this->deferralCodes->usage($facility->id)),
            ];
        }

        $changed = array_keys($code->getDirty());
        $code->updated_by = $staff->id;
        $code->save();

        $this->auditLogger->record($staff, 'center.deferral_code_changed', $code, [
            'facility_id' => $facility->id,
            'code' => $code->code,
            'previous_code' => $before !== $code->code ? $before : null,
            'changed' => array_values(array_map(
                fn (string $column): string => $column === 'retired_at' ? 'retired' : $column,
                $changed
            )),
        ]);

        return [
            'message' => match (true) {
                in_array('retired_at', $changed, true) && $code->retired_at !== null => "{$code->code} retired. Past records keep it.",
                in_array('retired_at', $changed, true) => "{$code->code} is offered again.",
                default => "{$code->code} saved.",
            },
            'data' => $this->format($code->load('updater'), true, $this->deferralCodes->usage($facility->id)),
        ];
    }

    /**
     * What a deferral is recorded under: one of the centre's codes with the
     * reason it stands for today, or "Other" with the reason typed and no
     * code (project owner, 2026-10-24). The donor is then told neither.
     *
     * @param  array<string, mixed>  $payload  deferral_code, or other_reason
     * @return array{code: string|null, reason: string}
     */
    public function chosen(Facility $facility, array $payload): array
    {
        $other = trim((string) ($payload['other_reason'] ?? ''));

        if ($other !== '' && blank($payload['deferral_code'] ?? null)) {
            return ['code' => null, 'reason' => $other];
        }

        $code = $this->resolve($facility, $payload['deferral_code'] ?? null);

        return ['code' => $code->code, 'reason' => $code->reason];
    }

    /**
     * The active code a deferral is being recorded under, or a validation
     * error on the given field.
     */
    public function resolve(Facility $facility, ?string $code, string $field = 'deferral_code'): DeferralCode
    {
        $this->ensureDefaults($facility);

        return ($code === null || trim($code) === '' ? null : $this->deferralCodes->findActive($facility->id, $code))
            ?? throw ValidationException::withMessages([
                $field => 'Choose one of this centre\'s deferral codes, or Other.',
            ]);
    }

    /**
     * Whether a viewer may read what a code stands for.
     */
    public function seesReasons(User $viewer): bool
    {
        return $viewer->can('donors.view_clinical') || $viewer->can('deferrals.configure');
    }

    /**
     * Seed a centre's first list. Only ever once: codes are retired, not
     * deleted, so a centre that has any has had its list.
     */
    private function ensureDefaults(Facility $facility): void
    {
        if (! $this->deferralCodes->hasAny($facility->id)) {
            $this->deferralCodes->seed($facility->id, DeferralCode::DEFAULTS);
        }
    }

    /**
     * @param  array<string, int>  $usage
     * @return array<string, mixed>
     */
    private function format(DeferralCode $code, bool $withReason, array $usage): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'reason' => $withReason ? $code->reason : null,
            'retired' => $code->retired_at !== null,
            'retired_at' => $code->retired_at?->toISOString(),
            ...($withReason ? [
                'uses' => $usage[$code->code] ?? 0,
                'updated_at' => $code->updated_at?->toISOString(),
                'updated_by' => $code->updater?->signatureName(),
            ] : []),
        ];
    }

    /**
     * The facility the caller acts for, resolved from the token rather than input.
     */
    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
