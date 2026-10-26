<?php

namespace App\Service;

use App\Enums\DonorEmailTemplate;
use App\Mail\DonorCenterEmail;
use App\Models\Facility;
use App\Models\FacilityEmailTemplate;
use App\Models\User;
use App\Support\DonorStanding;

/**
 * Turns a centre's template and one donor into the email that donor receives.
 *
 * Templates are plain text with {placeholders}. A placeholder this class does
 * not know is left as typed, so a typo shows in the preview rather than
 * vanishing from the sent email. Values are inserted as plain text and escaped
 * by the view, like the template around them.
 */
class DonorEmailComposer
{
    /**
     * The placeholders a template may use, as Settings lists them.
     *
     * @var array<string, string>
     */
    public const PLACEHOLDERS = [
        '{first_name}' => 'The donor\'s first name',
        '{full_name}' => 'The donor\'s full name',
        '{donor_code}' => 'The donor\'s ID, e.g. DONOR-000032',
        '{last_donation_date}' => 'Their last donation, e.g. 28 July 2026',
        '{next_eligible_date}' => 'When they may give again, or "today"',
        '{total_donations}' => 'How many times they have given',
        '{center_name}' => 'Your centre\'s name',
        '{center_phone}' => 'Your centre\'s phone number',
        '{center_email}' => 'Your centre\'s email address',
        '{center_address}' => 'Your centre\'s address',
    ];

    /**
     * The centre's wording for a template, or the default when it has none.
     *
     * @return array{subject: string, body: string, customised: bool}
     */
    public function templateFor(Facility $facility, DonorEmailTemplate $template): array
    {
        $saved = FacilityEmailTemplate::query()
            ->where('facility_id', $facility->id)
            ->where('template', $template->value)
            ->first();

        return [
            'subject' => $saved?->subject ?? $template->defaultSubject(),
            'body' => $saved?->body ?? $template->defaultBody(),
            'customised' => $saved !== null,
        ];
    }

    /**
     * Build the email for one donor.
     *
     * @param  array{subject: string, body: string}|null  $wording  the centre's template, when the caller already has it
     */
    public function compose(
        Facility $facility,
        DonorEmailTemplate $template,
        User $donor,
        DonorStanding $standing,
        ?array $wording = null
    ): DonorCenterEmail {
        $wording ??= $this->templateFor($facility, $template);
        $values = $this->values($facility, $donor, $standing);

        $subject = trim(preg_replace('/\s+/', ' ', strtr($wording['subject'], $values)) ?? '');

        // Blank lines separate paragraphs; single line breaks stay inside one.
        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\R\s*\R/', strtr(str_replace("\r\n", "\n", $wording['body']), $values)) ?: []),
            fn (string $paragraph): bool => $paragraph !== ''
        ));

        return new DonorCenterEmail(
            centerName: $facility->name,
            centerEmail: $facility->email,
            centerPhone: $facility->phone,
            centerAddress: $facility->address,
            subjectLine: $subject,
            paragraphs: $paragraphs,
        );
    }

    /**
     * @return array<string, string>
     */
    private function values(Facility $facility, User $donor, DonorStanding $standing): array
    {
        $next = $standing->nextEligibleDate;

        return [
            '{first_name}' => (string) $donor->first_name,
            '{full_name}' => trim($donor->first_name.' '.$donor->last_name),
            '{donor_code}' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
            '{last_donation_date}' => $standing->lastDonationAt?->format('j F Y') ?? '',
            '{next_eligible_date}' => $next === null || ! $next->isAfter(today()) ? 'today' : $next->format('j F Y'),
            '{total_donations}' => (string) $standing->totalDonations,
            '{center_name}' => (string) $facility->name,
            '{center_phone}' => (string) $facility->phone,
            '{center_email}' => (string) $facility->email,
            '{center_address}' => (string) $facility->address,
        ];
    }
}
