<?php

namespace App\Enums;

enum AccountStatus: string
{
    case PendingVerification = 'pending_verification';

    case Active = 'active';

    case Suspended = 'suspended';

    case Deactivated = 'deactivated';

    /**
     * Determine whether an account in this state is allowed to authenticate.
     */
    public function canAuthenticate(): bool
    {
        return match ($this) {
            self::PendingVerification, self::Active => true,
            self::Suspended, self::Deactivated => false,
        };
    }

    /**
     * Get the client-facing error code explaining why authentication was refused.
     */
    public function authenticationBlockedCode(): ?string
    {
        return match ($this) {
            self::Suspended => 'account_suspended',
            self::Deactivated => 'account_deactivated',
            default => null,
        };
    }

    /**
     * Get the human-readable message explaining why authentication was refused.
     */
    public function authenticationBlockedMessage(): ?string
    {
        return match ($this) {
            self::Suspended => 'This account has been suspended. Please contact support.',
            self::Deactivated => 'This account has been deactivated.',
            default => null,
        };
    }
}
