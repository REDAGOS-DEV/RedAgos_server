<?php

namespace App\Enums;

/**
 * What a questionnaire row is actually asking.
 *
 * Almost every question on the DOH form asks about a risk or an exposure.
 * Question 29 does not -- it asks the donor to confirm they understand that a
 * negative test does not mean their blood is safe. Rendering that among the
 * risk answers as a bare "Yes" loses what it is, both for the donor answering
 * it and for the nurse reading it back.
 */
enum QuestionKind: string
{
    case Risk = 'risk';

    case Acknowledgement = 'acknowledgement';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
