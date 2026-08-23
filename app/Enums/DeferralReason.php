<?php

namespace App\Enums;

enum DeferralReason: string
{
    case BelowMinimumAge = 'below_min_age';

    case BelowMinimumWeight = 'below_min_weight';

    case BelowMinimumInterval = 'below_min_interval';

    case QuestionnaireResponse = 'questionnaire_response';

    /**
     * Get the donor-facing explanation for this deferral.
     */
    public function message(): string
    {
        return match ($this) {
            self::BelowMinimumAge => 'You must be at least 18 years old to donate blood.',
            self::BelowMinimumWeight => 'Donors must weigh at least 50 kilograms.',
            self::BelowMinimumInterval => 'You have not yet reached the minimum 56-day interval since your last donation.',
            self::QuestionnaireResponse => 'One or more of your questionnaire answers requires review by blood centre staff.',
        };
    }
}
