<?php

namespace App\Enums;

/**
 * The "Type of Donor" tick-boxes on Section I-A.
 *
 * Deliberately has no database column. Every one of these is answerable from
 * donation records -- whether this centre has seen the donor before, whether
 * they have ever donated at all, and how long ago the last draw was. The rule
 * is EligibilityRuleEvaluator's: these come from the records, "never from the
 * numbers typed into the questionnaire". A donor-declared copy beside a derived
 * one is two contradictory answers in the same payload.
 *
 * DonorQuestionnaireService resolves the case and reports it with
 * donor_type_source: "derived_from_records", so the counter knows the tick was
 * not the donor's own.
 */
enum DonorType: string
{
    /**
     * Has donated before, but never at this blood centre.
     */
    case NewToCentre = 'new_to_centre';

    /**
     * Has never completed a donation anywhere in RedAgos.
     */
    case FirstTime = 'first_time';

    /**
     * Has donated at this centre before and is within the lapse threshold.
     */
    case RepeatRetained = 'repeat_retained';

    /**
     * Has donated before, but not within the lapse threshold.
     */
    case Lapsed = 'lapsed';

    public function label(): string
    {
        return match ($this) {
            self::NewToCentre => 'New to SNBC-M',
            self::FirstTime => 'First time',
            self::RepeatRetained => 'Repeat/Retained',
            self::Lapsed => 'Lapsed',
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
