<?php

namespace App\Service;

use App\Enums\ReferralStatus;
use App\Enums\SerologyMarker;
use App\Mail\DonorCenterEmail;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\DonorContactRequested;
use App\Repository\CounsellingReferralRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The Testing department's follow-up of donors whose serology came back reactive.
 *
 * Section I-C of the DOH form has every donor agree to be referred for
 * counselling if found reactive; this is where the centre keeps that promise.
 * Referrals are opened by LaboratoryService, never here — a referral exists
 * because a reactive reading was recorded, not because someone typed one in.
 *
 * This is the only surface that names which marker a donor was reactive for,
 * which is why it has its own ability (`lab.referrals`) and why every read of
 * the list is audited.
 *
 * The donor is asked to contact the centre from here, by hand
 * (requestContact()), like a Donor Management email: once the Laboratory
 * Supervisor has verified the result, not by itself the moment a reading is
 * saved (project owner, 2026-10-18).
 */
class CounsellingReferralService
{
    public function __construct(
        private readonly CounsellingReferralRepository $counsellingReferralRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Ask the donor to contact the centre: by email when they have an
     * address, and always in the app.
     *
     * The wording is fixed, not a Settings template. Section I-C promises the
     * donor that no result is issued to them, so it names no test, result,
     * infection or deferral, only that the centre would like to talk about
     * their donation; a template anyone could edit could say more. It goes
     * out as the centre (DonorCenterEmail), and replies reach the centre.
     *
     * The email is queued first: if that fails, nothing has been sent or
     * stamped and the staff member may simply try again.
     *
     * @return array<string, mixed>
     */
    public function requestContact(User $staff, int $referralId): array
    {
        $facility = $this->requireFacility($staff);

        $referral = $this->counsellingReferralRepository->findReferral($referralId, $facility->id)
            ?? throw $this->refuse(404, 'referral_not_found', 'That referral was not found at your facility.');

        if (! $referral->status->isOpen()) {
            throw $this->refuse(409, 'referral_closed', 'This referral is closed and can no longer be changed.');
        }

        $donor = $referral->donorProfile?->donor
            ?? throw $this->refuse(422, 'donor_missing', 'This referral has no donor account to contact.');

        $donation = $referral->donation;
        $byEmail = $donor->hasEmailAddress();

        if ($byEmail) {
            try {
                Mail::to($donor->email, trim($donor->first_name.' '.$donor->last_name))
                    ->queue($this->contactEmail($facility, $donor, $donation));
            } catch (Throwable $exception) {
                Log::warning('Could not queue a contact request email.', [
                    'referral_id' => $referral->id,
                    'exception' => $exception->getMessage(),
                ]);

                throw $this->refuse(503, 'email_not_sent', 'The email could not be sent. Please try again in a moment.');
            }
        }

        try {
            $donor->notify(new DonorContactRequested($donation));
        } catch (Throwable $exception) {
            Log::warning('Could not send the in-app contact request.', [
                'referral_id' => $referral->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        $referral->forceFill([
            'contact_requested_at' => now(),
            'contact_requested_by' => $staff->id,
        ])->save();

        $this->auditLogger->record($staff, 'referral.contact_requested', $referral, [
            'facility_id' => $facility->id,
            'by_email' => $byEmail,
        ]);

        return [
            'message' => $byEmail
                ? "Contact request sent to {$donor->first_name} by email and in the app."
                : "{$donor->first_name} has no email address, so the contact request was sent in the app only.",
            'data' => $this->format($this->counsellingReferralRepository->findReferral($referralId, $facility->id)),
        ];
    }

    /**
     * Page this facility's referrals.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(User $staff, array $filters, int $perPage): array
    {
        $facility = $this->requireFacility($staff);

        $page = $this->counsellingReferralRepository
            ->paginateForFacility($facility->id, $filters, $perPage)
            ->through(fn (CounsellingReferral $referral): array => $this->format($referral));

        $this->auditLogger->record($staff, 'referral.list_viewed', null, [
            'facility_id' => $facility->id,
            'status' => $filters['status'] ?? 'open',
            'count' => $page->count(),
        ]);

        return [
            ...$page->toArray(),
            'pending_count' => $this->counsellingReferralRepository->pendingCount($facility->id),
        ];
    }

    /**
     * How many referrals nobody has acted on yet, for the sidebar's badge.
     *
     * Not audited, unlike list(): a count names no donor and no marker, and
     * the sidebar asks on every page.
     *
     * @return array{pending_count: int}
     */
    public function summary(User $staff): array
    {
        $facility = $this->requireFacility($staff);

        return ['pending_count' => $this->counsellingReferralRepository->pendingCount($facility->id)];
    }

    /**
     * Move a referral on, and record who did and when.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(User $staff, int $referralId, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $target = ReferralStatus::from($payload['status']);

        $from = DB::transaction(function () use ($staff, $facility, $referralId, $payload, $target): ReferralStatus {
            $locked = $this->counsellingReferralRepository->lockReferral($referralId, $facility->id)
                ?? throw $this->refuse(404, 'referral_not_found', 'That referral was not found at your facility.');

            $from = $locked->status;

            if ($from === ReferralStatus::Closed) {
                throw $this->refuse(409, 'referral_closed', 'This referral is closed and can no longer be changed.');
            }

            if (! $from->canMoveTo($target)) {
                throw $this->refuse(
                    409,
                    'referral_transition_invalid',
                    "A referral that is {$from->label()} cannot be marked {$target->label()}."
                );
            }

            $locked->status = $target;
            $locked->updated_by = $staff->id;

            if ($column = $target->timestampColumn()) {
                $locked->setAttribute($column, now());
            }

            if (array_key_exists('note', $payload)) {
                $note = is_string($payload['note']) ? trim($payload['note']) : null;
                $locked->note = $note === '' ? null : $note;
            }

            $locked->save();

            return $from;
        });

        $referral = $this->counsellingReferralRepository->findReferral($referralId, $facility->id);

        $this->auditLogger->record($staff, 'referral.status_changed', $referral, [
            'facility_id' => $facility->id,
            'from' => $from->value,
            'to' => $target->value,
        ]);

        return [
            'message' => 'Referral marked '.$target->label().'.',
            'data' => $this->format($referral),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function format(CounsellingReferral $referral): array
    {
        $donor = $referral->donorProfile?->donor;
        $profile = $referral->donorProfile;
        $donation = $referral->donation;
        $serology = $donation?->serology;

        return [
            'id' => $referral->id,
            'status' => $referral->status->value,
            'status_label' => $referral->status->label(),
            'is_open' => $referral->status->isOpen(),
            'flagged_at' => $referral->created_at?->toISOString(),
            'contacted_at' => $referral->contacted_at?->toISOString(),
            'referred_at' => $referral->referred_at?->toISOString(),
            'closed_at' => $referral->closed_at?->toISOString(),
            // When the donor was last asked to get in touch, and by whom: no
            // one for a request sent by itself before they were sent by hand.
            'contact_requested_at' => $referral->contact_requested_at?->toISOString(),
            'contact_requested_by' => $referral->contactRequester
                ? $referral->contactRequester->signatureName()
                : null,
            'note' => $referral->note,
            'updated_by' => $referral->updater
                ? $referral->updater->signatureName()
                : null,
            'donor' => $donor === null ? null : [
                'uuid' => $donor->uuid,
                'donor_code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
                'full_name' => trim($donor->first_name.' '.$donor->last_name),
                'phone' => $donor->phone,
                'email' => $donor->hasEmailAddress() ? $donor->email : null,
                // Section I-C's contact person, for a donor who cannot be reached.
                'contact_person' => $profile?->contact_person_name,
                'contact_person_number' => $profile?->contact_person_number,
            ],
            'donation' => $donation === null ? null : [
                'id' => $donation->id,
                'donation_date' => $donation->donation_date?->toISOString(),
                'donation_barcode' => $donation->collection?->donation_barcode,
            ],
            'reactive_markers' => $serology === null ? [] : array_map(
                fn (SerologyMarker $marker): array => ['value' => $marker->value, 'label' => $marker->label()],
                $serology->reactiveMarkers()
            ),
            'screened_by' => $serology?->recorder
                ? $serology->recorder->signatureName()
                : null,
            'screened_at' => $serology?->recorded_at?->toISOString(),
            'verified_by' => $serology?->verifier
                ? $serology->verifier->signatureName()
                : null,
            'verified_at' => $serology?->verified_at?->toISOString(),
        ];
    }

    /**
     * The contact request email, in the centre's name.
     */
    private function contactEmail(Facility $facility, User $donor, ?Donation $donation): DonorCenterEmail
    {
        $when = $donation?->donation_date ? ' on '.$donation->donation_date->format('j F Y') : '';
        $call = filled($facility->phone) ? 'Please call us on '.$facility->phone.' or reply to this email' : 'Please reply to this email or call us';

        return new DonorCenterEmail(
            centerName: $facility->name,
            centerEmail: $facility->email,
            centerPhone: $facility->phone,
            centerAddress: $facility->address,
            subjectLine: 'Please contact '.$facility->name,
            paragraphs: [
                'Dear '.$donor->first_name.',',
                'Thank you for donating blood at '.$facility->name.$when.'.',
                'Our staff would like to speak with you about your donation. '.$call.' at your earliest convenience.',
            ],
        );
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
