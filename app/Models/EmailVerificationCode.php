<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The six-digit code that verifies an email address (2026-11-03), typed back
 * into the portal the way a Twilio Verify code is, in place of the signed link.
 *
 * One per account; issue() replaces whatever was there, so only the latest
 * code mailed works. A code lives auth.verification.expire minutes and allows
 * MAX_ATTEMPTS tries, after which a new one has to be sent.
 *
 * Only an HMAC of the code, keyed with the app key, is stored, and it is
 * hidden from serialisation. A plain hash of six digits would fall to a
 * million guesses the moment the table leaked.
 */
class EmailVerificationCode extends Model
{
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'user_id',
        'email',
        'code_hash',
        'attempts',
        'expires_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issue a fresh code for the user's current address, replacing any code
     * sent before it. Returns the plaintext, which is never stored.
     */
    public static function issue(User $user): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        static::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'email' => $user->email,
                'code_hash' => self::hash($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::lifetimeMinutes()),
            ]
        );

        return $code;
    }

    /**
     * Minutes a code can be entered for.
     */
    public static function lifetimeMinutes(): int
    {
        return (int) config('auth.verification.expire', 10);
    }

    /**
     * Whether this code can still be tried for the user's current address.
     */
    public function isLiveFor(User $user): bool
    {
        return $this->email === $user->email
            && $this->attempts < self::MAX_ATTEMPTS
            && $this->expires_at->isFuture();
    }

    /**
     * Count one try, and say whether it was still allowed.
     *
     * A single conditional UPDATE, so guesses sent at the same moment cannot
     * all read the same count and slip past the limit together.
     */
    public function claimAttempt(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts') === 1;
    }

    public function matches(string $code): bool
    {
        return hash_equals($this->code_hash, self::hash($code));
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
