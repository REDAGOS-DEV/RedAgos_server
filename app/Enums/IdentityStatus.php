<?php

namespace App\Enums;

enum IdentityStatus: string
{
    case Unsubmitted = 'unsubmitted';

    case Pending = 'pending';

    case Verified = 'verified';

    case Rejected = 'rejected';

    /**
     * Determine whether a donor in this state may submit a document.
     *
     * A verified document is final; changing it would silently invalidate a
     * decision an administrator already recorded.
     */
    public function acceptsSubmission(): bool
    {
        return $this !== self::Verified;
    }

    /**
     * Determine whether a document in this state is awaiting an administrator.
     */
    public function awaitsReview(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Get every status value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
