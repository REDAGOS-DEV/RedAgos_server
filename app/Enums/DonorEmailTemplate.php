<?php

namespace App\Enums;

/**
 * The emails a blood centre's staff can send a donor from the Donors page.
 *
 * Each centre may reword both in Settings (`facility_email_templates`); these
 * defaults are what a centre that never has sends. Plain text with
 * {placeholders} — see DonorEmailComposer for the list and how each renders.
 */
enum DonorEmailTemplate: string
{
    case ThankYou = 'thank_you';

    case EligibilityReminder = 'eligibility_reminder';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::ThankYou => 'Thank-You',
            self::EligibilityReminder => 'Eligibility Reminder',
        };
    }

    public function defaultSubject(): string
    {
        return match ($this) {
            self::ThankYou => 'Thank you for donating at {center_name}',
            self::EligibilityReminder => 'You can donate blood again at {center_name}',
        };
    }

    public function defaultBody(): string
    {
        return match ($this) {
            self::ThankYou => <<<'TEXT'
                Dear {first_name},

                Thank you for donating blood at {center_name}. Your donation on {last_donation_date} will help patients who need it.

                Please rest well and drink plenty of fluids today. You can donate again from {next_eligible_date}.

                With gratitude,
                {center_name}
                {center_phone}
                TEXT,
            self::EligibilityReminder => <<<'TEXT'
                Dear {first_name},

                You can donate blood again from {next_eligible_date}. One donation can help save up to three lives.

                Visit us at {center_address}, or reply to this email to arrange a time.

                Thank you for being a donor,
                {center_name}
                {center_phone}
                TEXT,
        };
    }
}
