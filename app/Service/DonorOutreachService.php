<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\DonorEmailTemplate;
use App\Enums\FacilityDonorStatus;
use App\Models\Facility;
use App\Models\FacilityDonor;
use App\Models\FacilityEmailTemplate;
use App\Models\User;
use App\Repository\DonorDirectoryRepository;
use App\Support\DonorNotificationPreferences;
use App\Support\DonorStanding;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The emails a blood centre sends its donors from the Donors page, and the
 * wording it keeps for them in Settings.
 *
 * Who receives an eligibility reminder is decided here and only here, so the
 * count the confirmation dialog shows is the count that is sent. A donor is
 * reminded only when every one of these holds:
 *
 *  - they are on this centre's list (the same filters as the page) and active here;
 *  - they have an email address and an account that is not suspended or closed;
 *  - they have not turned eligibility emails off in their portal;
 *  - they are eligible today: the donation interval has passed and no
 *    deferral — standing, or a temporary one still their latest screening —
 *    is on their record;
 *  - this centre has not reminded them in the last REMINDER_COOLDOWN_DAYS days,
 *    so a second click, or a colleague's, does not mail them twice.
 */
class DonorOutreachService
{
    public const REMINDER_COOLDOWN_DAYS = 7;

    private const CHUNK = 200;

    public function __construct(
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly DonorStandingResolver $donorStandingResolver,
        private readonly DonorEmailComposer $donorEmailComposer,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Send one donor one of the centre's emails.
     *
     * Stricter about the thank-you than the bulk reminder is about anything:
     * staff choose this donor, so the rules are about the email making sense.
     * A thank-you needs a donation at this centre to thank them for. A
     * reminder is refused for a standing deferral, an inactive donor, or one
     * who turned reminders off; a temporary deferral is left to the staff
     * member's judgement, since nothing records when it ends.
     *
     * @return array<string, mixed>
     */
    public function sendOne(User $staff, string $uuid, DonorEmailTemplate $template): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findDonor($uuid)
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        if (! $this->donorDirectoryRepository->facilityHasRelationship($donor->id, $facility->id)) {
            throw $this->refuse(403, 'donor_not_at_facility', 'Only a centre this donor has visited or registered with can email them.');
        }

        if (! $donor->hasEmailAddress()) {
            throw $this->refuse(422, 'no_email', 'This donor has no email address on record.');
        }

        if (! $this->accountOpen($donor)) {
            throw $this->refuse(422, 'account_closed', 'This donor\'s account is suspended or closed.');
        }

        $standing = $this->donorStandingResolver->forDonor($facility->id, $donor);
        $record = $this->donorDirectoryRepository->facilityRecord($facility->id, $donor->id);

        if ($template === DonorEmailTemplate::ThankYou && $standing->donationsHere === 0) {
            throw $this->refuse(422, 'no_donation_here', 'There is no donation at this centre to thank them for yet.');
        }

        if ($template === DonorEmailTemplate::EligibilityReminder) {
            match (true) {
                $standing->hasStandingDeferral() => throw $this->refuse(422, 'donor_deferred', 'This donor is deferred, so they cannot be reminded to donate.'),
                $standing->eligibility() === DonorStanding::IN_PROGRESS => throw $this->refuse(422, 'visit_in_progress', 'This donor is in a visit at your centre right now.'),
                $this->isInactive($record) => throw $this->refuse(422, 'donor_inactive', 'This donor is inactive at your centre. Mark them active to remind them.'),
                ! $this->acceptsReminders($donor) => throw $this->refuse(422, 'reminders_off', 'This donor has turned off eligibility reminders.'),
                default => null,
            };
        }

        $email = $this->donorEmailComposer->compose($facility, $template, $donor, $standing);

        try {
            Mail::to($donor->email, trim($donor->first_name.' '.$donor->last_name))->queue($email);
        } catch (Throwable $exception) {
            Log::warning('Could not queue a donor email.', [
                'donor_id' => $donor->id,
                'template' => $template->value,
                'exception' => $exception->getMessage(),
            ]);

            throw $this->refuse(503, 'email_not_sent', 'The email could not be sent. Please try again in a moment.');
        }

        if ($template === DonorEmailTemplate::EligibilityReminder) {
            $this->donorDirectoryRepository->markReminded($facility->id, [$donor->id], now());
        }

        $this->auditLogger->record($staff, 'donor.emailed', $donor, [
            'facility_id' => $facility->id,
            'template' => $template->value,
        ]);

        return [
            'message' => $template->label().' email sent to '.$donor->first_name.'.',
        ];
    }

    /**
     * Who the eligibility reminder would reach, without sending anything.
     *
     * @param  array<string, mixed>  $filters  the page's filters
     * @return array{recipients: int, skipped: array<string, int>}
     */
    public function previewReminders(User $staff, array $filters): array
    {
        $facility = $this->requireFacility($staff);
        $result = ['recipients' => 0, 'skipped' => $this->noneSkipped()];

        $this->eachCandidate($facility, $filters, function (array $recipients, array $skipped) use (&$result): void {
            $result['recipients'] += count($recipients);
            $result['skipped'] = $this->addSkipped($result['skipped'], $skipped);
        });

        return $result;
    }

    /**
     * Email every active, eligible donor on the list that may be reminded.
     *
     * One donor's mail failing does not stop the run; they are counted as
     * failed and not marked reminded, so the next run picks them up.
     *
     * @param  array<string, mixed>  $filters  the page's filters
     * @return array{message: string, sent: int, failed: int, skipped: array<string, int>}
     */
    public function sendReminders(User $staff, array $filters): array
    {
        $facility = $this->requireFacility($staff);
        $wording = $this->donorEmailComposer->templateFor($facility, DonorEmailTemplate::EligibilityReminder);
        $sent = 0;
        $failed = 0;
        $skipped = $this->noneSkipped();

        $this->eachCandidate($facility, $filters, function (array $recipients, array $chunkSkipped) use ($facility, $wording, &$sent, &$failed, &$skipped): void {
            $skipped = $this->addSkipped($skipped, $chunkSkipped);
            $reminded = [];

            foreach ($recipients as [$donor, $standing]) {
                try {
                    Mail::to($donor->email, trim($donor->first_name.' '.$donor->last_name))->queue(
                        $this->donorEmailComposer->compose($facility, DonorEmailTemplate::EligibilityReminder, $donor, $standing, $wording)
                    );

                    $reminded[] = $donor->id;
                    $sent++;
                } catch (Throwable $exception) {
                    $failed++;

                    Log::warning('Could not queue an eligibility reminder.', [
                        'donor_id' => $donor->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }

            $this->donorDirectoryRepository->markReminded($facility->id, $reminded, now());
        });

        $this->auditLogger->record($staff, 'donors.eligibility_reminders_sent', $facility, [
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'filters' => array_intersect_key($filters, array_flip(['search', 'blood_type_id'])),
        ]);

        return [
            'message' => match (true) {
                $sent === 0 => 'No donor on this list was due a reminder.',
                $sent === 1 => 'Reminder sent to 1 eligible donor.',
                default => "Reminders sent to {$sent} eligible donors.",
            },
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * The centre's two templates, as Settings edits them.
     *
     * @return array<string, mixed>
     */
    public function templates(User $staff): array
    {
        $facility = $this->requireFacility($staff);

        return [
            'templates' => array_map(fn (DonorEmailTemplate $template): array => [
                'template' => $template->value,
                'label' => $template->label(),
                ...$this->donorEmailComposer->templateFor($facility, $template),
                'default_subject' => $template->defaultSubject(),
                'default_body' => $template->defaultBody(),
            ], DonorEmailTemplate::cases()),
            'placeholders' => collect(DonorEmailComposer::PLACEHOLDERS)
                ->map(fn (string $description, string $key): array => ['key' => $key, 'description' => $description])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{subject: string, body: string}  $wording
     * @return array<string, mixed>
     */
    public function saveTemplate(User $staff, DonorEmailTemplate $template, array $wording): array
    {
        $facility = $this->requireFacility($staff);

        FacilityEmailTemplate::query()->updateOrCreate(
            ['facility_id' => $facility->id, 'template' => $template->value],
            ['subject' => trim($wording['subject']), 'body' => trim($wording['body']), 'updated_by' => $staff->id]
        );

        $this->auditLogger->record($staff, 'facility.email_template_saved', $facility, ['template' => $template->value]);

        return [
            'message' => $template->label().' template saved.',
            ...$this->templates($staff),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resetTemplate(User $staff, DonorEmailTemplate $template): array
    {
        $facility = $this->requireFacility($staff);

        FacilityEmailTemplate::query()
            ->where('facility_id', $facility->id)
            ->where('template', $template->value)
            ->delete();

        $this->auditLogger->record($staff, 'facility.email_template_reset', $facility, ['template' => $template->value]);

        return [
            'message' => $template->label().' template restored to the default.',
            ...$this->templates($staff),
        ];
    }

    /**
     * Walk the centre's active donors on the filtered list, a chunk at a time,
     * handing over who may be reminded and why the rest may not.
     *
     * @param  array<string, mixed>  $filters
     * @param  callable(array<int, array{0: User, 1: DonorStanding}>, array<string, int>): void  $handle
     */
    private function eachCandidate(Facility $facility, array $filters, callable $handle): void
    {
        $filters = [
            ...array_intersect_key($filters, array_flip(['search', 'blood_type_id'])),
            'status' => FacilityDonorStatus::Active->value,
        ];

        $cooldownStart = now()->subDays(self::REMINDER_COOLDOWN_DAYS);

        $this->donorDirectoryRepository
            ->facilityDonorQuery($facility->id, $filters)
            ->reorder()
            ->chunkById(self::CHUNK, function ($donors) use ($facility, $cooldownStart, $handle): void {
                $standings = $this->donorStandingResolver->forDonors($facility->id, $donors);
                $records = $this->donorDirectoryRepository->facilityRecordsFor($facility->id, $donors->pluck('id')->all());
                $recipients = [];
                $skipped = $this->noneSkipped();

                foreach ($donors as $donor) {
                    $standing = $standings[$donor->id];
                    $remindedAt = $records[$donor->id]?->eligibility_reminded_at ?? null;

                    $reason = match (true) {
                        ! $donor->hasEmailAddress() || ! $this->accountOpen($donor) => 'no_email',
                        ! $this->acceptsReminders($donor) => 'opted_out',
                        $standing->eligibility() === DonorStanding::DEFERRED => 'deferred',
                        $standing->eligibility() === DonorStanding::IN_PROGRESS => 'in_progress',
                        $standing->eligibility() === DonorStanding::WAITING => 'not_yet_eligible',
                        $remindedAt !== null && $remindedAt->isAfter($cooldownStart) => 'recently_reminded',
                        default => null,
                    };

                    if ($reason === null) {
                        $recipients[] = [$donor, $standing];
                    } else {
                        $skipped[$reason]++;
                    }
                }

                $handle($recipients, $skipped);
            }, 'users.id', 'id');
    }

    /**
     * @return array<string, int>
     */
    private function noneSkipped(): array
    {
        return ['no_email' => 0, 'opted_out' => 0, 'deferred' => 0, 'in_progress' => 0, 'not_yet_eligible' => 0, 'recently_reminded' => 0];
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    private function addSkipped(array $a, array $b): array
    {
        foreach ($b as $reason => $count) {
            $a[$reason] = ($a[$reason] ?? 0) + $count;
        }

        return $a;
    }

    private function accountOpen(User $donor): bool
    {
        return ! in_array($donor->account_status, [AccountStatus::Suspended, AccountStatus::Deactivated], true);
    }

    private function acceptsReminders(User $donor): bool
    {
        return DonorNotificationPreferences::allows($donor, DonorNotificationPreferences::ELIGIBILITY_RENEWAL);
    }

    private function isInactive(?FacilityDonor $record): bool
    {
        return $record?->status === FacilityDonorStatus::Inactive;
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
