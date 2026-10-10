<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One hosted checkout opened with the payment provider for a statement.
 *
 * Not money: an attempt may be paid, expire, be cancelled or fail. When the
 * server has confirmed one by re-fetching it from the provider, it produces
 * exactly one payment. Its amount is its statement revision's amount due.
 */
class PaymentAttempt extends Model
{
    protected $fillable = [
        'billing_id',
        'billing_revision_id',
        'initiated_by',
        'initiator_facility_id',
        'provider',
        'provider_account_id',
        'reference_id',
        'provider_session_id',
        'provider_payment_request_id',
        'provider_payment_id',
        'amount_centavos',
        'currency',
        'payer_name',
        'status',
        'checkout_url',
        'expires_at',
        'completed_at',
        'failure_code',
        'verification_attempts',
        'next_verification_at',
        'verification_deadline_at',
        'review_required_at',
        'review_reason',
        'last_event_at',
    ];

    /**
     * The watcher's name is personal data: stored encrypted, never serialised.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'payer_name',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'payer_name' => 'encrypted',
            'amount_centavos' => 'integer',
            'verification_attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'next_verification_at' => 'immutable_datetime',
            'verification_deadline_at' => 'immutable_datetime',
            'review_required_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(BillingRevision::class, 'billing_revision_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class, 'payment_attempt_id');
    }

    /**
     * Limit the query to attempts that still hold their statement open.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', PaymentAttemptStatus::open());
    }
}
