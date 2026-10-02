<?php

namespace App\Enums;

/**
 * Whether a donor's health questionnaire has been answered and still stands.
 *
 * Deliberately not App\Enums\EligibilityStatus. That one is the `result` column
 * on eligibility_screenings and carries the vocabulary of a verdict -- eligible,
 * deferred -- which RedAgos no longer reaches about a donor's own answers. Every
 * screening is now recorded `pending`: answered, awaiting the blood centre's
 * decision at the counter.
 *
 * This is what the donor and their app are told instead, and it says nothing
 * about whether they may give blood.
 */
enum QuestionnaireStatus: string
{
    /**
     * The donor has never completed the questionnaire.
     */
    case NotAnswered = 'not_answered';

    /**
     * Answered, and within its validity window.
     */
    case Answered = 'answered';

    /**
     * Answered, but the answers have aged past their validity window.
     */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::NotAnswered => 'Not yet answered',
            self::Answered => 'Answered',
            self::Expired => 'Needs answering again',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
