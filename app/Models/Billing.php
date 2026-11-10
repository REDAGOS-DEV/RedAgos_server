<?php

namespace App\Models;

use App\Enums\BillingStatus;
use App\Support\Money;
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
        'settled_at',
        'settled_by',
        'settlement_reference',
        'settlement_note',
        'discount_id',
        'discount_name',
        'discount_kind',
        'discount_value',
        'discount_amount',
        'discount_note',
        'discounted_by',
        'discounted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BillingStatus::class,
            'total_amount' => 'decimal:2',
            'billing_date' => 'immutable_datetime',
            'settled_at' => 'immutable_date',
            'payment_deferred_at' => 'immutable_datetime',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'discounted_at' => 'immutable_datetime',
        ];
    }

    /** Whether one of the centre's discounts is on this bill. The government subsidy is not one. */
    public function hasDiscount(): bool
    {
        return $this->discount_kind !== null;
    }

    /**
     * The staff member who recorded that the hospital settled a weekly bill.
     */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /**
     * The bill's journal: every money event on it, oldest first.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BillingTransaction::class)->orderBy('id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'request_id');
    }

    /**
     * The staff member whose allocation first raised the statement.
     *
     * The statement is raised by BillingService::syncFor() on the first
     * allocation, so this is usually an Issuance user, not Billing staff. The
     * column means "statement created by", as decided by the project owner on
     * 2026-10-10; who took a payment is on the payment (recorded_by).
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
     * The issued Statements of Account, oldest first.
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(BillingRevision::class)->orderBy('revision_number');
    }

    /**
     * The gateway checkouts opened against this statement.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('id');
    }

    /**
     * Determine whether this statement clears its request's units for release.
     *
     * Either its status settles it, which the enum alone decides, or Issuance
     * decided the units may go first and the bill is settled afterwards
     * (project owner, 2026-11-07).
     */
    public function clearsRelease(): bool
    {
        return $this->status->clearsRelease() || $this->payment_deferred_at !== null;
    }

    /**
     * Determine whether money is still owed on a statement whose units need not wait for it.
     *
     * False once it is settled, whatever was decided before: a deferred bill
     * that has since been paid is simply paid.
     */
    public function isPaymentDeferred(): bool
    {
        return $this->payment_deferred_at !== null && ! $this->status->clearsRelease();
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
        return Money::toCentavos($this->total_amount) === 0;
    }

    /**
     * Limit the query to statements with money still outstanding.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [BillingStatus::Unpaid, BillingStatus::Partial]);
    }

    /**
     * Limit the query to statements that clear their request's units for release.
     *
     * Nested, so its "or" cannot escape the request a whereHas() ties it to.
     */
    public function scopeClearingRelease(Builder $query): Builder
    {
        return $query->where(fn (Builder $clearing): Builder => $clearing
            ->whereIn('status', array_values(array_filter(
                BillingStatus::cases(),
                fn (BillingStatus $status): bool => $status->clearsRelease()
            )))
            ->orWhereNotNull('payment_deferred_at'));
    }
}
