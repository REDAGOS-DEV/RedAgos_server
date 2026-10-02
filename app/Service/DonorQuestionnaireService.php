<?php

namespace App\Service;

use App\Enums\DonorType;
use App\Models\DonorProfile;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\Facility;
use App\Models\User;
use App\Repository\CollectionRepository;
use App\Repository\DonorDirectoryRepository;
use App\Repository\EligibilityRepository;
use App\Support\OperationalDay;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * Sections I-A to I-C of the DOH questionnaire, as the counter reads them.
 *
 * Kept out of CollectionService, which is chartered to carry a donation from
 * registration to collection and is long enough already. This class has one
 * job: decide whether a facility may read a donor's questionnaire, then shape
 * it for the screen.
 *
 * On the response: this is the donor's own declaration, and the blood centre is
 * the one that decides what it means. So the payload carries every answer, the
 * review markers, and the server's advisory assessment -- none of which the
 * donor ever saw -- and states plainly that it is not the on-site screening.
 */
class DonorQuestionnaireService
{
    /**
     * A donor's donations are "lapsed" after this long without one.
     *
     * Only ever used to pick the Type of Donor tick-box on the printed form.
     * It decides nothing.
     */
    private const LAPSED_AFTER_DAYS = 365;

    public function __construct(
        private readonly EligibilityRepository $eligibilityRepository,
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly CollectionRepository $collectionRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EligibilityRuleEvaluator $evaluator
    ) {}

    /**
     * Read a donor's questionnaire on behalf of a blood centre.
     *
     * @return array<string, mixed>
     */
    public function show(User $staff, string $donorUuid, ?int $screeningId = null): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findDonor($donorUuid)
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        $this->guardDonorHasPresented($donor->id, $facility);

        [$screening, $source] = $this->resolveScreening($donor->id, $screeningId);

        $this->auditLogger->record($staff, 'collection.questionnaire_viewed', $donor, [
            'facility_id' => $facility->id,
            'screening_id' => $screening->id,
            'question_version' => $screening->question_version,
            'source' => $source,
        ]);

        return ['data' => $this->present($screening, $source)];
    }

    /**
     * The small reference the scan response carries, without any health data.
     *
     * Enough to draw the counter's summary strip and decide whether to warn
     * about a superseded form or missing consent, and nothing more: the scan
     * response is about who is at the counter, not about their history.
     *
     * @return array<string, mixed>
     */
    public function reference(?EligibilityScreening $screening): array
    {
        if ($screening === null) {
            return [
                'available' => false,
                'screening_id' => null,
                'question_version' => null,
                'is_current_version' => null,
                'question_count' => null,
                'screened_on' => null,
                'valid_until' => null,
                'consent_captured' => false,
            ];
        }

        return [
            'available' => true,
            'screening_id' => $screening->id,
            'question_version' => (int) $screening->question_version,
            'is_current_version' => (int) $screening->question_version === $this->currentVersion(),
            'question_count' => $screening->answers()->count(),
            'screened_on' => $screening->screened_at?->toDateString(),
            'valid_until' => $screening->valid_until?->toDateString(),
            'consent_captured' => $screening->hasConsent(),
        ];
    }

    /**
     * Pick the screening this facility should be shown, and say which rule chose it.
     *
     * The pin from a scan gives exact precision. Without one -- the manual
     * valid-ID path -- the donor's live credential points at the newest
     * screening, and failing that the newest screening is shown regardless of
     * its state. A donor whose token was revoked still has a questionnaire the
     * counter needs to read.
     *
     * @return array{0: EligibilityScreening, 1: string}
     */
    private function resolveScreening(int $donorId, ?int $screeningId): array
    {
        if ($screeningId !== null) {
            $pinned = $this->eligibilityRepository->screeningWithAnswers($screeningId);

            if ($pinned === null || (int) $pinned->donor_id !== $donorId) {
                throw $this->refuse(404, 'questionnaire_not_found', 'That questionnaire does not belong to this donor.');
            }

            return [$pinned, 'qr_token'];
        }

        $token = $this->eligibilityRepository->usableQrToken($donorId);

        if ($token?->screening_id !== null) {
            $fromToken = $this->eligibilityRepository->screeningWithAnswers((int) $token->screening_id);

            if ($fromToken !== null) {
                return [$fromToken, 'qr_token'];
            }
        }

        $latest = $this->eligibilityRepository->latestScreeningWithAnswers($donorId)
            ?? throw $this->refuse(
                404,
                'questionnaire_not_found',
                'This donor has not completed the health questionnaire in the app.'
            );

        return [$latest, 'latest_screening'];
    }

    /**
     * Refuse a facility the donor has not presented themselves at.
     *
     * DonorDirectoryService withholds a donor's record from a centre they have
     * never donated at, on the grounds that detailed records stay with the
     * facility that created them. That rule does not apply here, and applying
     * it would withhold the questionnaire from every first-time donor -- the
     * ones whose answers most need reading.
     *
     * The distinction: the questionnaire is not another facility's record. It
     * is the donor's own declaration, addressed to whichever centre they hand
     * it to. So the gate is presentation, not relationship -- which is also
     * stricter than donors.view alone, because it closes the hole where any
     * Collection member could pull a questionnaire for a donor they found by
     * browsing the directory.
     */
    private function guardDonorHasPresented(int $donorId, Facility $facility): void
    {
        $today = OperationalDay::todayAsDate();

        $presented = $this->collectionRepository->todaysAppointmentFor($donorId, $facility->id, $today) !== null
            || $this->donorDirectoryRepository->openDonationAtFacility($donorId, $facility->id) !== null
            || $this->eligibilityRepository->qrVerifiedAtFacilityOn($donorId, $facility->id, $today)
            || $this->donorDirectoryRepository->facilityHasRelationship($donorId, $facility->id);

        if ($presented) {
            return;
        }

        throw $this->refuse(
            403,
            'donor_not_presented',
            'This donor has not presented at your facility. Open the walk-in donation to review their questionnaire.'
        );
    }

    /**
     * Shape Sections I-A, I-B and I-C for the screen.
     *
     * @return array<string, mixed>
     */
    private function present(EligibilityScreening $screening, string $source): array
    {
        $version = (int) $screening->question_version;
        $questions = $this->eligibilityRepository->questionTextMap($version);
        $answers = $screening->answers->pluck('answer', 'question_code');
        $flagged = $this->evaluator->flaggedAnswers($questions->values(), $answers->all());

        return [
            'screening_id' => $screening->id,
            'source' => $source,
            'question_version' => $version,
            'is_current_version' => $version === $this->currentVersion(),
            'screened_at' => $screening->screened_at?->toDateString(),
            'valid_until' => $screening->valid_until?->toDateString(),
            'is_expired' => $screening->valid_until !== null && ! $screening->valid_until->isFuture(),

            // The donor never saw this. It is the server's own read of the
            // answers, offered to staff as a prompt and nothing more.
            'computed_assessment' => $screening->computed_result,

            'notice' => 'Declared by the donor in the RedAgos app. This is not the on-site screening.',

            'personal_data' => $this->personalData($screening),
            'sections' => $this->sections($screening, $questions, $answers, $flagged),
            'answered_count' => $answers->count(),
            'question_count' => $questions->count(),
            'flagged_codes' => $flagged,
            'consent' => $this->consent($screening),

            'vitals_declared' => [
                'weight_kg' => $screening->weight_kg,
                'age_at_screening' => $screening->age_at_screening,
                'last_menstrual_period' => $screening->last_menstrual_period?->toDateString(),
            ],
        ];
    }

    /**
     * Section I-A, with the derived fields marked as derived.
     *
     * @return array<string, mixed>
     */
    private function personalData(EligibilityScreening $screening): array
    {
        $profile = $screening->donorProfile;
        $donor = $profile?->donor;
        $summary = $this->donorDirectoryRepository->donationSummary((int) $screening->donor_id);

        $fields = [
            'donor_code' => 'DONOR-'.str_pad((string) $screening->donor_id, 6, '0', STR_PAD_LEFT),
            'last_name' => $donor?->last_name,
            'first_name' => $donor?->first_name,
            'middle_name' => $donor?->middle_name,
            'date_of_birth' => $profile?->birth_date?->toDateString(),
            // The age as it stood when the form was answered, not as it stands
            // now -- the same reason the questionnaire stores it at all.
            'age' => $screening->age_at_screening,
            'sex' => $screening->gender_at_screening ?? $profile?->gender,
            'civil_status' => $profile?->civil_status?->value,
            'civil_status_label' => $profile?->civil_status?->label(),
            'occupation' => $profile?->occupation,
            'nationality' => $profile?->nationality,
            'religion' => $profile?->religion,
            'home_address' => $profile?->address,
            'office_address' => $profile?->office_address,
            'preferred_mailing_address' => $profile?->preferred_mailing_address?->value,
            'contact_number' => $donor?->phone,
            'telephone_no' => $profile?->telephone_no,
            'email' => $donor?->email,
            'blood_type' => $profile?->bloodType?->code,
            'contact_person_name' => $profile?->contact_person_name,
            'contact_person_address' => $profile?->contact_person_address,
            'contact_person_number' => $profile?->contact_person_number,
        ];

        return $fields + [
            // Derived from donation records rather than declared, because a
            // donor-declared copy beside a derived one is two answers to the
            // same question.
            'donor_type' => $this->donorType($summary)->value,
            'donor_type_label' => $this->donorType($summary)->label(),
            'donor_type_source' => 'derived_from_records',
            'times_donated' => $summary['total_donations'],
            'last_donation' => [
                'date' => $summary['last_donation_at'],
                'source' => 'records',
                'declared_date' => $screening->declared_last_donation_date?->toDateString(),
                'declared_venue' => $screening->declared_last_donation_venue,
            ],

            // Named, so the counter prints "Not provided" rather than a blank
            // that reads like an unanswered line on the paper form.
            'missing_fields' => array_keys(array_filter(
                $fields,
                static fn ($value): bool => $value === null || $value === ''
            )),
        ];
    }

    /**
     * Which Type of Donor box the printed form would have ticked.
     *
     * @param  array{total_donations: int, last_donation_at: ?string}  $summary
     */
    private function donorType(array $summary): DonorType
    {
        if ($summary['total_donations'] === 0) {
            return DonorType::FirstTime;
        }

        $last = $summary['last_donation_at'];

        if ($last === null) {
            return DonorType::NewToCentre;
        }

        return OperationalDay::today()->diffInDays($last) > self::LAPSED_AFTER_DAYS
            ? DonorType::Lapsed
            : DonorType::RepeatRetained;
    }

    /**
     * Section I-B, in the form's own order and headings.
     *
     * @param  Collection<string, EligibilityQuestion>  $questions
     * @param  Collection<string, bool>  $answers
     * @param  array<int, string>  $flagged
     * @return array<int, array<string, mixed>>
     */
    private function sections(
        EligibilityScreening $screening,
        Collection $questions,
        Collection $answers,
        array $flagged
    ): array {
        $gender = $screening->gender_at_screening;

        return $questions
            ->groupBy('section_key')
            ->map(fn (Collection $group, string $key): array => [
                'key' => $key,
                'number' => $group->first()->section_number,
                'title' => $group->first()->section_title ?? str($key)->headline()->value(),
                'questions' => $group
                    ->map(function (EligibilityQuestion $question) use ($answers, $flagged, $gender): array {
                        $applicable = $question->appliesToGender($gender);
                        $answered = $answers->has($question->code);
                        $answer = $answered ? (bool) $answers->get($question->code) : null;

                        return [
                            'code' => $question->code,
                            'number' => $question->number,
                            'text' => $question->text,
                            'kind' => $question->kind?->value ?? 'risk',
                            'applicable' => $applicable,
                            'answer' => $answer,
                            // Decided here so the screen never has to work out
                            // what a null means. Unanswered is not "No", and a
                            // question that was never put to this donor is not
                            // the same as one they declined.
                            'answer_label' => match (true) {
                                ! $applicable => 'Not applicable',
                                ! $answered => 'Not answered',
                                $answer => 'Yes',
                                default => 'No',
                            },
                            // True where the answer trips the question's review
                            // marker. It highlights a row; it defers nobody.
                            'flagged' => in_array($question->code, $flagged, true),
                        ];
                    })
                    ->values()
                    ->all(),
            ])
            ->sortBy('number')
            ->values()
            ->all();
    }

    /**
     * Section I-C, and an explicit negative when there is nothing to show.
     *
     * @return array<string, mixed>
     */
    private function consent(EligibilityScreening $screening): array
    {
        if (! $screening->hasConsent()) {
            return [
                'captured' => false,
                'consented_on' => null,
                'version' => null,
                'statements' => [],
                'note' => 'This questionnaire predates the informed-consent requirement. No consent is on file.',
            ];
        }

        $version = (string) $screening->consent_version;
        $statements = (array) config('donor_consent.versions.'.$version.'.statements', []);

        return [
            'captured' => true,
            'consented_on' => $screening->consented_at?->toDateString(),
            'consented_at' => $screening->consented_at?->toISOString(),
            'version' => $version,
            'statements' => $statements,
            // Today's wording beside yesterday's timestamp would misrepresent
            // what the donor actually agreed to, so say it is gone instead.
            'note' => $statements === []
                ? "Consent text version {$version} is no longer on file."
                : null,
        ];
    }

    private function currentVersion(): int
    {
        return (int) config('donation.questionnaire_version');
    }

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
