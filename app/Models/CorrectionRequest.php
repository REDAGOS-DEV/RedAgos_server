<?php

namespace App\Models;

use App\Enums\CorrectionSubject;
use App\Enums\CorrectionTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A request to correct a saved record, and the decision on it.
 *
 * Nothing is mass-assignable except what the requester supplies; the status
 * and the review are written by CorrectionService alone.
 *
 * Exactly one target column is set — the one the subject's target names. The
 * saving hook enforces it everywhere; on PostgreSQL a CHECK constraint does
 * as well.
 */
class CorrectionRequest extends Model
{
    protected $fillable = [
        'facility_id',
        'donation_id',
        'blood_unit_id',
        'request_allocation_id',
        'payment_id',
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

    protected static function booted(): void
    {
        static::saving(function (CorrectionRequest $correction): void {
            $expected = $correction->subject->target()->column();

            foreach (CorrectionTarget::cases() as $target) {
                $column = $target->column();
                $set = $correction->getAttribute($column) !== null;

                if ($column === $expected && ! $set) {
                    throw new LogicException("A {$correction->subject->value} correction must name its {$column}.");
                }

                if ($column !== $expected && $set) {
                    throw new LogicException("A {$correction->subject->value} correction cannot also name a {$column}.");
                }
            }
        });
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function bloodUnit(): BelongsTo
    {
        return $this->belongsTo(BloodUnit::class, 'blood_unit_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(RequestAllocation::class, 'request_allocation_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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

    /**
     * The key of whatever this correction is about.
     */
    public function targetKey(): int|string
    {
        return $this->getAttribute($this->subject->target()->column());
    }
}
