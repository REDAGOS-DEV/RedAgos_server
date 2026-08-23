<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A check-in credential. Only the SHA-256 hash is stored, and it is hidden from
 * serialisation so it can never reach a response body.
 */
class DonorQrToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'donor_id',
        'screening_id',
        'token_hash',
        'issued_at',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function donorProfile(): BelongsTo
    {
        return $this->belongsTo(DonorProfile::class, 'donor_id', 'donor_id');
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(EligibilityScreening::class, 'screening_id');
    }

    /**
     * Determine whether this token can still be presented at a blood centre.
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * Limit the query to tokens that are neither revoked nor expired.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
