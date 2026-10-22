<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\FacilityDonorStatus;
use App\Enums\IdentityStatus;
use App\Enums\RoleName;
use App\Enums\ScreeningOutcome;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\FacilityDonor;
use App\Models\User;
use App\Repository\AuthRepository;
use App\Repository\BloodCenterRepository;
use App\Repository\DonorDeferralRepository;
use App\Repository\DonorDirectoryRepository;
use App\Support\AccountIdentity;
use App\Support\DonorAvatar;
use App\Support\DonorNotificationPreferences;
use App\Support\DonorStanding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Donor records as one blood centre is permitted to see them.
 *
 * Two response shapes, decided by whether this facility has dealt with the
 * donor before:
 *
 *  - Own donor: the full record, including this facility's donations in detail.
 *  - Anyone else, found by an exact identifier: identity plus a standardised
 *    donation summary. Counts and dates, never another centre's records.
 *
 * The split is a privacy rule, not a convenience. Detailed records stay with
 * the facility that created them.
 */
class DonorDirectoryService
{
    public function __construct(
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly BloodCenterRepository $bloodCenterRepository,
        private readonly AuthRepository $authRepository,
        private readonly AuditLogger $auditLogger,
        private readonly DonorDeferralRepository $donorDeferralRepository,
        private readonly DonorStandingResolver $donorStandingResolver,
        private readonly DonorQuestionnaireService $donorQuestionnaireService
    ) {}

    /**
     * Page the donors this facility has dealt with.
     *
     * Two shapes, by role. A holder of donors.view (the counter and the
     * screening physician) gets the registration summary. Anyone reaching the
     * list on donors.view_contact alone — Recruitment — gets a contact list:
     * who to call and when they may give again, with no blood type or birth
     * date, which are part of the donor's record rather than their contact
     * details.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function browse(User $staff, array $filters, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($staff);
        $full = $staff->can('donors.view');

        $page = $this->donorDirectoryRepository->browseForFacility($facility->id, $filters, $perPage);

        // Counts, deferrals and this centre's records for the whole page in a
        // fixed number of queries, rather than three more per row.
        $donors = $page->getCollection();
        $standings = $this->donorStandingResolver->forDonors($facility->id, $donors);
        $records = $this->donorDirectoryRepository->facilityRecordsFor($facility->id, $donors->pluck('id')->all());

        return $page->through(fn (User $donor): array => $full
            ? $this->summarise($donor, $standings[$donor->id], $records[$donor->id] ?? null)
            : $this->contact($donor, $standings[$donor->id], $records[$donor->id] ?? null));
    }

    /**
     * Find one donor by an identifier presented in person.
     *
     * The lookup is audited whichever shape it returns: reading a donor this
     * centre has never met is exactly the access that needs a trail.
     *
     * Reachable on appointments.verify, so the collection chair can confirm
     * the person in front of them. A caller without donors.view gets the
     * identity fields and the deferral notice only — enough to match the ID
     * card, not the registration record.
     *
     * @return array<string, mixed>
     */
    public function lookup(User $staff, string $type, string $value): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findByExactIdentifier($type, $value)
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        $isOwn = $this->donorDirectoryRepository->facilityHasRelationship($donor->id, $facility->id);

        $this->auditLogger->record($staff, 'donor.looked_up', $donor, [
            'facility_id' => $facility->id,
            'identifier_type' => $type,
            'scope' => $isOwn ? 'own_facility' : 'cross_facility',
        ]);

        $shape = $staff->can('donors.view')
            ? $this->present($donor, $facility, $isOwn)
            : [...$this->identity($donor), 'scope' => $isOwn ? 'own_facility' : 'cross_facility', 'restricted' => true];

        return [
            ...$shape,

            // The same notice the QR scan carries, for a donor found by the ID
            // card in their hand instead. Without it a donor who left their
            // phone at home walks past the one warning that they must never
            // be bled. Outcome and date only — never a reason, never which
            // department recorded it — so it is safe in either shape.
            'prior_deferral' => $this->donorDeferralRepository->standingDeferralFor($donor->id)?->toArray(),

            // The same reference the scan carries, so a counter that found a
            // walk-in by search knows whether a questionnaire is on file
            // before it lets the physician accept them. Metadata only; the
            // answers stay behind their own gated read.
            'health_questionnaire' => $this->donorQuestionnaireService->referenceForDonor($donor->id),
        ];
    }

    /**
     * Show one donor, in whichever shape this facility is entitled to.
     *
     * @return array<string, mixed>
     */
    public function show(User $staff, string $uuid): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findDonor($uuid)
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        $isOwn = $this->donorDirectoryRepository->facilityHasRelationship($donor->id, $facility->id);

        $this->auditLogger->record($staff, 'donor.viewed', $donor, [
            'facility_id' => $facility->id,
            'scope' => $isOwn ? 'own_facility' : 'cross_facility',
        ]);

        return $this->present($donor, $facility, $isOwn);
    }

    /**
     * List this facility's donations for one donor, in detail.
     *
     * Refuses outright rather than returning an empty list when the facility
     * has no relationship with the donor: an empty array would read as "this
     * donor has never donated", which is a different and false claim.
     *
     * @return array<string, mixed>
     */
    public function history(User $staff, string $uuid): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findDonor($uuid)
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        if (! $this->donorDirectoryRepository->facilityHasRelationship($donor->id, $facility->id)) {
            throw $this->refuse(
                403,
                'donor_not_at_facility',
                'Detailed records stay with the facility that created them. Only a summary is available for this donor.'
            );
        }

        $donations = $this->donorDirectoryRepository->donationsAtFacility($donor->id, $facility->id);

        return [
            'donor' => $this->identity($donor),
            'donations' => $donations->map(fn (Donation $donation): array => [
                'id' => $donation->id,
                'donation_date' => $donation->donation_date?->toISOString(),
                'status' => $donation->status?->value,
                'status_label' => $donation->status?->label(),
                'volume_ml' => $donation->volume_ml,

                // What the profile's history table lists: the bag's barcode,
                // what Processing made of it, and where it was given.
                'bag' => $donation->collection?->donation_barcode,
                'components' => $donation->components
                    ->map(fn ($line): ?string => $line->component?->code ?: $line->component?->name)
                    ->filter()
                    ->values()
                    ->all(),
                'center' => $donation->facility?->name,

                // What the screening officer decided, and why. Without this a
                // deferred visit reads as a bare "Rejected" with the reason
                // recorded nowhere a colleague can find it — which is the whole
                // question a staff member opens this page to answer.
                //
                // This is the gated surface the scan response deliberately
                // withholds the reason from: the endpoint already refuses
                // unless the caller's facility has a relationship with the
                // donor.
                'screening' => $donation->screening === null ? null : [
                    'outcome' => $donation->screening->outcome?->value,
                    'outcome_label' => $donation->screening->outcome?->label(),
                    'is_deferral' => $donation->screening->outcome?->isDeferral() ?? false,
                    'is_blocking' => $donation->screening->outcome?->isBlocking() ?? false,
                    'deferral_reason' => $donation->screening->deferral_reason,
                    'screened_at' => $donation->screening->screened_at?->toISOString(),
                ],

                // A reactive serology result permanently defers the donor. The
                // counter sees that it happened and when — never the marker,
                // which only the Testing department's referral list shows.
                'laboratory_deferral' => $donation->counsellingReferral === null ? null : [
                    'outcome' => ScreeningOutcome::PermanentlyDeferred->value,
                    'outcome_label' => ScreeningOutcome::PermanentlyDeferred->label(),
                    'is_blocking' => true,
                    'recorded_at' => $donation->counsellingReferral->created_at?->toISOString(),
                ],

                // The final laboratory result, read-only: this endpoint sits
                // behind donors.view_clinical, which the screening physician
                // holds so they can clear or defer on the donor's history.
                // Overall result and typing only — never which marker.
                'laboratory' => $donation->testResult === null ? null : [
                    'result' => $donation->testResult->result?->value,
                    'result_label' => $donation->testResult->result?->label(),
                    'blood_type' => $donation->testResult->bloodType?->code,
                    'tested_at' => $donation->testResult->tested_at?->toISOString(),
                ],
            ])->all(),
        ];
    }

    /**
     * Register a donor who has presented at the counter.
     *
     * A valid ID number is required and unique: it is the identifier a walk-in
     * actually carries, and the thing that stops the same person being
     * registered twice under two spellings of their name. An email address is
     * optional — see the users.email migration for why inventing one is worse
     * than omitting it.
     *
     * A drive's paper record registers its new donors through here too, under
     * its own audit action, so the two paths can never write a donor
     * differently.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function registerWalkIn(User $staff, array $payload, string $auditAction = 'donor.registered_at_counter'): array
    {
        $facility = $this->requireFacility($staff);

        try {
            $donor = DB::transaction(function () use ($payload, $facility, $staff): User {
                $user = new User;

                $user->fill([
                    'uuid' => (string) Str::uuid(),
                    'first_name' => trim($payload['first_name']),
                    'last_name' => trim($payload['last_name']),
                    'email' => $payload['email'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'username' => AccountIdentity::buildUsername(
                        $payload['email'] ?? 'donor-'.Str::lower(Str::random(8))
                    ),
                    // A counter-registered donor has no password they chose.
                    // A random one is set so the column is never empty and the
                    // account cannot be signed into until it is reset.
                    'password' => Hash::make(Str::random(40)),
                    'account_status' => AccountStatus::PendingVerification,
                ]);

                $user->save();

                $this->bloodCenterRepository->attachRole($user, RoleName::Donor->value);

                $user->donorProfile()->create([
                    'donor_id' => $user->id,
                    'blood_type_id' => $payload['blood_type_id'] ?? null,
                    'gender' => $payload['gender'] ?? null,
                    'birth_date' => $payload['birth_date'] ?? null,
                    'address' => isset($payload['address']) ? trim((string) $payload['address']) : null,
                    'occupation' => isset($payload['occupation']) ? trim((string) $payload['occupation']) : null,
                    // What the donor says, for a donation RedAgos has no
                    // record of. A recorded draw always wins over it.
                    'last_donation_date' => $payload['last_donation_date'] ?? null,
                    'valid_id_number' => trim($payload['valid_id_number']),
                ]);

                // This centre's own record: the donor is on its list from now,
                // not from their first visit, and its staff may correct the
                // contact details they typed until the donor claims the account.
                $this->donorDirectoryRepository->saveFacilityRecord($facility->id, $user->id, [
                    'registered_here' => true,
                    'status' => $payload['status'] ?? FacilityDonorStatus::Active->value,
                    'notes' => $this->cleanNotes($payload['notes'] ?? null),
                    'updated_by' => $staff->id,
                ]);

                return $user;
            });
        } catch (QueryException $exception) {
            $this->rethrowUniqueViolation($exception);

            throw $exception;
        }

        // No verification link (project owner, 2026-10-21). Staff typed the
        // address, and a link mailed to a mistyped one would let its owner
        // confirm it and reset the password. The donor signs in with the
        // counter's slip (issueSignIn()) and confirms the address from the
        // portal, where only they can ask for the link.

        $this->auditLogger->record($staff, $auditAction, $donor, [
            'facility_id' => $facility->id,
            'has_email' => $donor->hasEmailAddress(),
        ]);

        return [
            'message' => $donor->first_name.' has been registered.',
            'data' => $this->present($donor->fresh(['donorProfile.bloodType']), $facility, true),
        ];
    }

    /**
     * Issue the donor a sign-in slip: their donor code (and address, if any)
     * and a temporary password, so they sign in to the app without
     * registering (project owner, 2026-10-21).
     *
     * Staff hold the donor's ID in front of them, which is why sign-in does not
     * then wait for an address to be confirmed (AuthService). Only for a donor
     * with no confirmed address: one with an address signs in and resets their
     * own password with it, and a desk that could replace that password could
     * take over any self-registered account.
     *
     * Issuing again replaces the last password and ends every session, so a
     * lost slip, or a claimed donor without an address who forgot their
     * password, is handled the same way. The password appears in this response
     * only; the audit row records that it was issued, never what it was.
     *
     * @return array<string, mixed>
     */
    public function issueSignIn(User $staff, string $uuid): array
    {
        $facility = $this->requireFacility($staff);
        $donor = $this->ownDonor($facility, $uuid);

        if (! $donor->account_status->canAuthenticate()) {
            throw $this->refuse(409, 'account_not_active', 'This donor account is suspended or deactivated, so it cannot be given a sign-in.');
        }

        if ($donor->hasVerifiedEmail()) {
            throw $this->refuse(
                409,
                'sign_in_managed_by_donor',
                'This donor signs in with their own email address. If they forgot their password, they can reset it from the sign-in page.'
            );
        }

        $reissued = $donor->sign_in_issued_at !== null;
        $password = AccountIdentity::temporaryPassword();

        $this->authRepository->issueTemporaryPassword($donor, $password);

        $this->auditLogger->record($staff, 'donor.sign_in_issued', $donor, [
            'facility_id' => $facility->id,
            'reissued' => $reissued,
        ]);

        return [
            'message' => 'Give '.$donor->first_name.' the sign-in slip. The temporary password is shown only once.',
            'data' => [
                'donor_code' => AccountIdentity::donorCode($donor->id),
                'email' => $donor->email,
                'temporary_password' => $password,
                'reissued' => $reissued,
            ],
        ];
    }

    /**
     * Correct a donor's details, and this centre's own status and notes.
     *
     * Only for a donor this centre has dealt with: correcting another centre's
     * donor would be writing to a record it may not even read. Three groups of
     * fields, three rules, because the donor shares this record with every
     * centre and with their own portal:
     *
     *  - Name and birth date are what an administrator checked against the
     *    donor's ID. Once the identity is verified they stay as verified.
     *  - Email and phone sign the donor in. Staff may correct them only for a
     *    donor this centre registered who has not yet claimed the account;
     *    otherwise a typo fix could hand someone else the account through a
     *    password reset. The donor changes their own from the portal.
     *  - Everything else — sex, blood type, address, occupation and a declared
     *    last donation — is the counter's to correct. The blood type
     *    especially: the laboratory refuses a typing that contradicts the
     *    profile and tells staff to have Collection correct it, which until
     *    now had no route.
     *
     * Status and notes are this centre's alone (facility_donors).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(User $staff, string $uuid, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $donor = $this->ownDonor($facility, $uuid);
        $profile = $donor->donorProfile;
        $record = $this->donorDirectoryRepository->facilityRecord($facility->id, $donor->id);
        $editable = $this->editable($donor, $record);

        $userChanges = $this->changed($donor, $payload, ['first_name', 'last_name', 'email', 'phone']);
        $profileChanges = $this->changed($profile, $payload, ['birth_date', 'gender', 'blood_type_id', 'address', 'occupation', 'last_donation_date']);

        $locked = [];

        foreach (['first_name', 'last_name', 'birth_date'] as $field) {
            if (! $editable['identity'] && (array_key_exists($field, $userChanges) || array_key_exists($field, $profileChanges))) {
                $locked[$field] = ['Verified against the donor\'s ID, so it cannot be changed here.'];
            }
        }

        foreach (['email', 'phone'] as $field) {
            if (! $editable['contact'] && array_key_exists($field, $userChanges)) {
                $locked[$field] = ['The donor manages this from their own account.'];
            }
        }

        if ($locked !== []) {
            throw ValidationException::withMessages($locked);
        }

        $recordChanges = [];

        if (array_key_exists('status', $payload) && $payload['status'] !== ($record?->status?->value ?? FacilityDonorStatus::Active->value)) {
            $recordChanges['status'] = $payload['status'];
        }

        if (array_key_exists('notes', $payload) && $this->cleanNotes($payload['notes']) !== $record?->notes) {
            $recordChanges['notes'] = $this->cleanNotes($payload['notes']);
        }

        $previousBloodType = $profile->bloodType?->code;
        $emailChanged = array_key_exists('email', $userChanges);

        try {
            DB::transaction(function () use ($donor, $profile, $userChanges, $profileChanges, $recordChanges, $emailChanged, $facility, $staff): void {
                if ($userChanges !== []) {
                    $donor->fill($userChanges);

                    // A new address has not been confirmed by anyone.
                    if ($emailChanged) {
                        $donor->email_verified_at = null;
                    }

                    $donor->save();
                }

                if ($profileChanges !== []) {
                    $profile->fill($profileChanges)->save();
                }

                if ($recordChanges !== []) {
                    $this->donorDirectoryRepository->saveFacilityRecord($facility->id, $donor->id, [
                        ...$recordChanges,
                        'updated_by' => $staff->id,
                    ]);
                }
            });
        } catch (QueryException $exception) {
            $this->rethrowUniqueViolation($exception);

            throw $exception;
        }

        // A corrected address is left unconfirmed and no link is mailed, for
        // the same reason as at registration: the donor confirms it from the
        // portal.
        $donor->refresh()->load('donorProfile.bloodType');

        $fields = array_keys([...$userChanges, ...$profileChanges, ...$recordChanges]);

        if ($fields !== []) {
            $this->auditLogger->record($staff, 'donor.updated', $donor, array_filter([
                'facility_id' => $facility->id,
                // Which fields, not their values: the audit trail must not
                // become a second copy of the donor's personal data. The blood
                // type is the exception, because a change to it is a clinical
                // correction someone will need to trace.
                'fields' => $fields,
                'blood_type' => array_key_exists('blood_type_id', $profileChanges)
                    ? ['from' => $previousBloodType, 'to' => $donor->donorProfile?->bloodType?->code]
                    : null,
            ]));
        }

        return [
            'message' => $fields === [] ? 'Nothing to change.' : trim($donor->first_name.' '.$donor->last_name).'\'s record has been updated.',
            'data' => $this->present($donor, $facility, true),
        ];
    }

    /**
     * Set a donor's photo, for staff to recognise them at the counter.
     *
     * The same column and private disk as the photo a donor uploads from their
     * portal, under the same rule as their email: only for a donor this
     * centre registered who has not claimed the account. After that the photo
     * is the donor's own.
     *
     * @return array<string, mixed>
     */
    public function updatePhoto(User $staff, string $uuid, UploadedFile $photo): array
    {
        $facility = $this->requireFacility($staff);
        $donor = $this->ownDonor($facility, $uuid);
        $record = $this->donorDirectoryRepository->facilityRecord($facility->id, $donor->id);

        if (! $this->editable($donor, $record)['photo']) {
            throw ValidationException::withMessages([
                'photo' => ['The donor manages their photo from their own account.'],
            ]);
        }

        $profile = $donor->donorProfile;
        $previous = $profile->profile_image_path;

        $profile->profile_image_path = $photo->store('avatars', 'local');
        $profile->save();

        if ($previous && Storage::disk('local')->exists($previous)) {
            Storage::disk('local')->delete($previous);
        }

        $this->auditLogger->record($staff, 'donor.photo_updated', $donor, ['facility_id' => $facility->id]);

        return [
            'message' => 'Photo saved.',
            'photo_url' => DonorAvatar::urlFor($donor->fresh('donorProfile')),
        ];
    }

    /**
     * Which groups of a donor's fields this centre's staff may change.
     *
     * @return array{identity: bool, contact: bool, photo: bool}
     */
    private function editable(User $donor, ?FacilityDonor $record): array
    {
        // Registered at this counter, and never taken over by the donor: no
        // password they chose, no address they confirmed.
        $unclaimedHere = ($record?->registered_here ?? false)
            && $donor->account_status === AccountStatus::PendingVerification
            && ! $donor->hasVerifiedEmail();

        return [
            'identity' => $donor->donorProfile?->identity_status !== IdentityStatus::Verified,
            'contact' => $unclaimedHere,
            'photo' => $unclaimedHere,
        ];
    }

    /**
     * The submitted fields whose value differs from the model's.
     *
     * Dates compare as Y-m-d and ids as integers, so re-saving an untouched
     * form changes nothing and audits nothing.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function changed(Model $model, array $payload, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $new = is_string($payload[$field]) ? trim($payload[$field]) : $payload[$field];
            $new = $new === '' ? null : $new;
            $current = $model->getAttribute($field);

            $current = match (true) {
                $current instanceof \DateTimeInterface => $current->format('Y-m-d'),
                $current instanceof \BackedEnum => $current->value,
                default => $current,
            };

            if ((string) $current !== (string) $new) {
                $changes[$field] = $new;
            }
        }

        return $changes;
    }

    private function cleanNotes(mixed $notes): ?string
    {
        $notes = is_string($notes) ? trim($notes) : null;

        return $notes === '' ? null : $notes;
    }

    /**
     * A donor this centre has dealt with, or a refusal.
     */
    private function ownDonor(Facility $facility, string $uuid): User
    {
        $donor = $this->donorDirectoryRepository->findDonor($uuid)
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        if (! $this->donorDirectoryRepository->facilityHasRelationship($donor->id, $facility->id)) {
            throw $this->refuse(
                403,
                'donor_not_at_facility',
                'Only a centre this donor has visited or registered with can change their record.'
            );
        }

        return $donor;
    }

    /**
     * Build the response for a donor, in the shape this facility may see.
     *
     * @return array<string, mixed>
     */
    private function present(User $donor, Facility $facility, bool $isOwn): array
    {
        $standing = $this->donorStandingResolver->forDonor($facility->id, $donor);

        $payload = [
            ...$this->identity($donor),
            'scope' => $isOwn ? 'own_facility' : 'cross_facility',
            'donation_summary' => [
                'total_donations' => $standing->totalDonations,
                'last_donation_at' => $standing->lastDonationAt?->toDateString(),
                'next_eligible_date' => $standing->nextEligibleDate?->toDateString(),
            ],
        ];

        if (! $isOwn) {
            // The standardised cross-facility view stops here. No address, no
            // per-donation detail, and nothing naming where they donated.
            $payload['restricted'] = true;
            $payload['restriction_note'] = 'This donor has not donated at your facility. Detailed records stay with the facility that created them.';

            return $payload;
        }

        $profile = $donor->donorProfile;
        $record = $this->donorDirectoryRepository->facilityRecord($facility->id, $donor->id);

        return [
            ...$payload,
            ...$standing->toArray(withDeferral: true),
            'restricted' => false,
            'address' => $profile?->address,
            'gender' => $profile?->gender,
            'occupation' => $profile?->occupation,
            'blood_type_id' => $profile?->blood_type_id,
            'declared_last_donation_date' => $profile?->last_donation_date?->toDateString(),
            'photo_url' => DonorAvatar::urlFor($donor),

            // This centre's own record.
            'status' => $this->statusOf($record),
            'notes' => $record?->notes,
            'registered_here' => $record?->registered_here ?? false,
            'eligibility_reminded_at' => $record?->eligibility_reminded_at?->toISOString(),

            // Whether the donor turned eligibility reminders off in their portal.
            'accepts_eligibility_reminders' => $this->acceptsEligibilityReminders($donor),
            'editable' => $this->editable($donor, $record),

            'valid_id_number' => $profile?->valid_id_number,
            // Status and type only. The document itself is reachable solely
            // through the authenticated route, which blood-centre staff are not
            // authorised for: they match the physical card at the counter.
            'valid_id_type' => $profile?->valid_id_type?->value,
            'identity_status' => ($profile?->identity_status ?? IdentityStatus::Unsubmitted)->value,
            'account_status' => $donor->account_status?->value,
            'email_verified' => $donor->hasVerifiedEmail(),
            'sign_in' => $this->signInState($donor),
            'donations_at_this_facility' => $this->donorDirectoryRepository
                ->donationsAtFacility($donor->id, $facility->id)
                ->count(),
        ];
    }

    /**
     * Where the counter's sign-in slip stands, for the desk.
     *
     * Claimed means the donor has signed in and replaced the temporary
     * password. can_issue mirrors issueSignIn()'s own refusals.
     *
     * @return array{issued_at: string|null, claimed: bool, can_issue: bool}
     */
    private function signInState(User $donor): array
    {
        return [
            'issued_at' => $donor->sign_in_issued_at?->toISOString(),
            'claimed' => $donor->sign_in_issued_at !== null && ! $donor->password_change_required,
            'can_issue' => $donor->account_status->canAuthenticate() && ! $donor->hasVerifiedEmail(),
        ];
    }

    /**
     * The identity fields shared by both response shapes.
     *
     * Enough for staff holding the donor's ID card to confirm they have the
     * right person, and no more.
     *
     * @return array<string, mixed>
     */
    private function identity(User $donor): array
    {
        $profile = $donor->donorProfile;

        return [
            'uuid' => $donor->uuid,
            'donor_code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
            'full_name' => trim($donor->first_name.' '.$donor->last_name),
            'first_name' => $donor->first_name,
            'last_name' => $donor->last_name,
            'blood_type' => $profile?->bloodType?->code,
            'birth_date' => $profile?->birth_date?->toDateString(),
            'phone' => $donor->phone,
            'email' => $donor->email,
        ];
    }

    /**
     * Shape a donor for Recruitment's contact list.
     *
     * Name, how to reach them, and when they may next give. Nothing drawn from
     * the donor's clinical record.
     *
     * @return array<string, mixed>
     */
    private function contact(User $donor, DonorStanding $standing, ?FacilityDonor $record): array
    {
        return [
            'uuid' => $donor->uuid,
            'donor_code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
            'full_name' => trim($donor->first_name.' '.$donor->last_name),
            'first_name' => $donor->first_name,
            'last_name' => $donor->last_name,
            'phone' => $donor->phone,
            'email' => $donor->email,
            ...$standing->toArray(withDeferral: false),
            'status' => $this->statusOf($record),
            'contact_only' => true,
        ];
    }

    /**
     * Shape a donor for the browse listing.
     *
     * @return array<string, mixed>
     */
    private function summarise(User $donor, DonorStanding $standing, ?FacilityDonor $record): array
    {
        $profile = $donor->donorProfile;

        return [
            ...$this->identity($donor),
            'gender' => $profile?->gender,
            'address' => $profile?->address,
            'occupation' => $profile?->occupation,
            ...$standing->toArray(withDeferral: true),
            'status' => $this->statusOf($record),
        ];
    }

    private function statusOf(?FacilityDonor $record): string
    {
        return ($record?->status ?? FacilityDonorStatus::Active)->value;
    }

    /**
     * The donor's own switch for eligibility emails, on unless they turned it
     * off. A counter-registered donor has never seen it, so has none stored.
     */
    private function acceptsEligibilityReminders(User $donor): bool
    {
        return DonorNotificationPreferences::allows($donor, DonorNotificationPreferences::ELIGIBILITY_RENEWAL);
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

    /**
     * Translate a unique-index collision into a field-level validation error.
     */
    private function rethrowUniqueViolation(QueryException $exception): void
    {
        if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
            return;
        }

        $message = $exception->getMessage();

        $field = match (true) {
            str_contains($message, 'valid_id_number') => 'valid_id_number',
            str_contains($message, 'phone') => 'phone',
            default => 'email',
        };

        throw ValidationException::withMessages([
            $field => ['A donor with this value was just registered. Search for them instead.'],
        ]);
    }

    /**
     * Build the project's refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
