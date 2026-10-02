<?php

namespace App\Models;

use App\Enums\CorrectionSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to correct a saved record, and the decision on it.
 *
 * Nothing is mass-assignable except what the requester supplies; the status
 * and the review are written by CorrectionService alone.
 */
class CorrectionRequest extends Model
{
    protected $fillable = [
        'facility_id',
        'donation_id',
        'subject',
        'requested_by',
        'reason',
        'changes',
        'previous',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'subject' => CorrectionSubject::class,
            'changes' => 'array',
            'previous' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
