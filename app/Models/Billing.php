<?php

namespace App\Models;

use App\Enums\BillingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The statement raised against one blood request.
 *
 * One per request — billings.request_id is unique — so this is the single
 * answer to "what is owed for this request", not a running ledger. Individual
 * settlements are payments.
 *
 * Blood requests are currently funded by government subsidy, so a statement is
 * raised at zero and settled immediately. It is still raised: the release gate
 * asks this row whether anything is owed, and a request with no statement at
 * all cannot answer that question.
 */
class Billing extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'billed_by',
        'billing_date',
        'total_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => BillingStatus::class,
            'total_amount' => 'decimal:2',
            'billing_date' => 'immutable_datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'request_id');
    }

    /**
     * The billing staff member who raised the statement.
     */
    public function billedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'billing_id');
    }

    /**
     * Determine whether this statement clears its request's units for release.
     *
     * Delegates to the enum so the rule that "no unit is released without
     * confirmed payment" has exactly one definition.
     */
    public function clearsRelease(): bool
    {
        return $this->status->clearsRelease();
    }

    /**
     * Determine whether the statement asks for money at all.
     *
     * A zero statement is what a subsidised request produces. Distinguishing it
     * from a settled non-zero statement matters for reporting, which should not
     * claim the network collected fees it never charged.
     */
    public function isZeroRated(): bool
    {
        return (float) $this->total_amount === 0.0;
    }

    /**
     * Limit the query to statements with money still outstanding.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [BillingStatus::Unpaid, BillingStatus::Partial]);
    }
}
