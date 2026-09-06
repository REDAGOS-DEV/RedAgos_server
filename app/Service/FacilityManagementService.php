<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\FacilityTypeName;
use App\Models\Facility;
use App\Models\User;
use App\Repository\FacilityRepository;
use App\Support\AccountIdentity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Facility onboarding, performed by a Super Admin.
 *
 * This is the only way a blood centre or a hospital blood bank comes into
 * existence. Public self-registration was removed: an organisation that can
 * create its own facility can attach itself to real blood stock, and no
 * approval queue reliably catches that when the queue is the only gate.
 *
 * Both facility types run through the same path. Everything that differs
 * between them — the role granted, whether donor booking applies — is answered
 * by FacilityTypeName rather than by a branch here.
 */
class FacilityManagementService
{
    /**
     * How many derived usernames to try before letting the unique index decide.
     */
    private const USERNAME_ATTEMPTS = 5;

    public function __construct(
        private readonly FacilityRepository $facilityRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Page every facility, whatever its type or state.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->facilityRepository
            ->paginate($filters, $perPage)
            ->through(fn (Facility $facility): array => $this->format($facility));
    }

    /**
     * Create a facility and the primary account that will run its portal.
     *
     * One transaction covers both, so a facility never outlives a failed
     * account creation and vice versa. The facility is active immediately —
     * there is no queue to wait in when an administrator is the one creating
     * it — and the approval trail records who did it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(User $admin, array $payload): array
    {
        $type = FacilityTypeName::from($payload['facility_type']);
        $account = $payload['primary_account'];

        try {
            [$facility, $primary] = DB::transaction(function () use ($admin, $payload, $account, $type): array {
                $facility = $this->facilityRepository->createApprovedFacility(
                    $this->facilityAttributes($payload, $account, $type),
                    $type,
                    $admin
                );

                $primary = $this->facilityRepository->createPrimaryAccount([
                    'uuid' => (string) Str::uuid(),
                    'first_name' => trim($account['first_name']),
                    'last_name' => trim($account['last_name']),
                    // Already lowercased and normalised to E.164 by the request.
                    'email' => $account['email'],
                    'phone' => $account['phone'],
                    'username' => $this->resolveUsername($account),
                    'password' => Hash::make($account['password']),
                    // Sign-in is refused until the address is verified, so the
                    // account starts here and the link below is what activates
                    // it. An administrator creating the account does not make
                    // the address any more proven than self-registration did.
                    'account_status' => AccountStatus::PendingVerification,
                    'position' => trim($account['position']),
                ], $facility);

                $this->facilityRepository->attachRole($primary, $type->role());
                $this->facilityRepository->setPrimaryAccount($facility, $primary);

                return [$facility, $primary];
            });
        } catch (QueryException $exception) {
            $this->rethrowUniqueViolation($exception);

            throw $exception;
        }

        // Sent after the commit so a rolled-back creation never mails a live
        // verification link.
        $primary->sendEmailVerificationNotification();

        $this->auditLogger->record($admin, 'facility.created', $facility, [
            'super_admin_id' => $admin->id,
            'facility_id' => $facility->id,
            'facility_type' => $type->value,
            'primary_account_id' => $primary->id,
        ]);

        return [
            'message' => $facility->name.' has been created. The primary account must verify its email address before signing in.',
            'data' => $this->format($facility),
        ];
    }

    /**
     * Build the facility columns, keeping the operating fields to the types they apply to.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    private function facilityAttributes(array $payload, array $account, FacilityTypeName $type): array
    {
        $attributes = [
            'name' => trim($payload['name']),
            'doh_license_number' => trim($payload['doh_license_number']),
            'contact_person' => trim($account['first_name'].' '.$account['last_name']),
            'email' => $payload['email'],
            'phone' => $payload['phone'],
            'address' => trim($payload['address']),
            'description' => isset($payload['description']) ? trim((string) $payload['description']) : null,
            'operating_hours' => isset($payload['operating_hours']) ? trim((string) $payload['operating_hours']) : null,
        ];

        // A hospital blood bank draws on centres rather than collecting from
        // donors, so it is created closed to bookings and the slot columns keep
        // their defaults. Accepting a slot configuration and then ignoring it
        // would leave the booking screens describing a facility that never
        // takes appointments.
        if (! $type->acceptsDonorBookings()) {
            return [...$attributes, 'is_accepting_donations' => false];
        }

        return [
            ...$attributes,
            'is_accepting_donations' => $payload['is_accepting_donations'] ?? true,
            'slot_capacity' => $payload['slot_capacity'] ?? 4,
            'slot_interval_minutes' => $payload['slot_interval_minutes'] ?? 30,
            'slots_start_at' => $payload['slots_start_at'] ?? null,
            'slots_end_at' => $payload['slots_end_at'] ?? null,
        ];
    }

    /**
     * Settle on a username for the primary account.
     *
     * An administrator may supply one, in which case the request has already
     * checked it. Otherwise it is derived from the email address, and because
     * users.username is unique the derived value is checked before use. The
     * suffix is random, so a collision is remote — but retrying is cheaper than
     * failing an otherwise valid submission, and the index stays the real
     * guarantee either way.
     *
     * @param  array<string, mixed>  $account
     */
    private function resolveUsername(array $account): string
    {
        $supplied = isset($account['username']) ? trim((string) $account['username']) : '';

        if ($supplied !== '') {
            return $supplied;
        }

        $candidate = AccountIdentity::buildUsername($account['email']);

        for ($attempt = 0; $attempt < self::USERNAME_ATTEMPTS; $attempt++) {
            if (! $this->facilityRepository->usernameExists($candidate)) {
                return $candidate;
            }

            $candidate = AccountIdentity::buildUsername($account['email']);
        }

        return $candidate;
    }

    /**
     * Translate a unique-index collision into a field-level validation error.
     *
     * Only reachable when two submissions race past the FormRequest and collide
     * at the index, so this is the race guard rather than the everyday path.
     * Matching on the column name is driver-dependent, so an unrecognised
     * collision still yields a usable 422 rather than a 500.
     */
    private function rethrowUniqueViolation(QueryException $exception): void
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        // MySQL and sqlite report 23000; PostgreSQL reports 23505.
        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return;
        }

        $message = $exception->getMessage();
        $isAccount = str_contains($message, 'users');

        $field = match (true) {
            str_contains($message, 'doh_license_number') => 'doh_license_number',
            str_contains($message, 'username') => 'primary_account.username',
            str_contains($message, 'email') => $isAccount ? 'primary_account.email' : 'email',
            str_contains($message, 'phone') => $isAccount ? 'primary_account.phone' : 'phone',
            default => 'name',
        };

        throw ValidationException::withMessages([
            $field => ['This conflicts with a facility that was just created. Please check the details and try again.'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Facility $facility): array
    {
        $facility->loadMissing(['facilityType', 'primaryAccount']);

        // tryFrom rather than from: facility_types is an open table, and a row
        // seeded outside the two types this flow creates must still be listed
        // rather than crash the page it appears on.
        $type = FacilityTypeName::tryFrom((string) $facility->facilityType?->name);

        return [
            'id' => $facility->id,
            'name' => $facility->name,
            'facility_type' => $facility->facilityType?->name,
            'facility_type_label' => $type?->label(),
            'doh_license_number' => $facility->doh_license_number,
            'contact_person' => $facility->contact_person,
            'email' => $facility->email,
            'phone' => $facility->phone,
            'address' => $facility->address,
            'description' => $facility->description,
            'operating_hours' => $facility->operating_hours,
            'is_accepting_donations' => (bool) $facility->is_accepting_donations,
            'status' => $facility->status->value,
            'approved_at' => $facility->approved_at?->toIso8601String(),
            'approved_by' => $facility->approved_by,
            'rejection_reason' => $facility->rejection_reason,
            'created_at' => $facility->created_at?->toIso8601String(),
            'primary_account' => $this->formatPrimaryAccount($facility->primaryAccount),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatPrimaryAccount(?User $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'uuid' => $account->uuid,
            'full_name' => trim($account->first_name.' '.$account->last_name),
            'email' => $account->email,
            'phone' => $account->phone,
            'position' => $account->position,
            'account_status' => $account->account_status?->value,
            'email_verified' => $account->hasVerifiedEmail(),
            'is_supervisor' => (bool) $account->is_supervisor,
        ];
    }
}
