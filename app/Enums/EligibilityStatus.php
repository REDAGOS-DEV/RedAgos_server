<?php

namespace App\Enums;

enum EligibilityStatus: string
{
    /**
     * The donor has never completed a screening.
     */
    case Pending = 'pending';

    /**
     * A preliminary screening was passed and is still within its validity window.
     */
    case Eligible = 'eligible';

    /**
     * A preliminary screening flagged at least one deferral reason.
     */
    case Deferred = 'deferred';

    /**
     * A previously passed screening has aged past its validity window.
     */
    case Expired = 'expired';
}
