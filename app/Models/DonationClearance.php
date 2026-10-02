<?php

namespace App\Models;

use App\Enums\ClearanceKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A digital clearance token: one department's statement that a donation passed its part of testing.
 *
 * A token is what lets a unit leave quarantine, so it is never deleted and
 * never edited — with one exception: it may be revoked, once, when an approved
 * correction replaces the result it stood on (CorrectionService). A revoked
 * token stays on the row as history; the corrected result earns a new one if
 * it qualifies. The model refuses anything else, whoever asks.
 */
class DonationClearance extends Model
{
    protected $fillable = [
        'donation_id',
        'kind',
        'issued_by',
        'issued_at',
        'source',
    ];

    /**
     * The only columns a revocation may write.
     *
     * @var array<int, string>
     */
    private const REVOCATION = ['revoked_at', 'revoked_by', 'revoked_reason', 'updated_at'];

    protected function casts(): array
    {
        return [
            'kind' => ClearanceKind::class,
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DonationClearance $clearance): void {
            $revoking = $clearance->getOriginal('revoked_at') === null
                && $clearance->revoked_at !== null
                && array_diff(array_keys($clearance->getDirty()), self::REVOCATION) === [];

            if (! $revoking) {
                throw new LogicException('A clearance token cannot be changed once issued, only revoked once.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A clearance token cannot be withdrawn once issued.');
        });
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * The staff member whose act issued the token, or null for a backfilled one.
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
