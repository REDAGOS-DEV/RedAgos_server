<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One confirmed settlement against a statement.
 *
 * A row here is money received: billing staff took it (manual) or a payment
 * provider confirmed it and the server verified that confirmation (gateway).
 * Attempts that may still fail belong elsewhere, so that summing a statement's
 * payments can never count money nobody holds. Rows recorded before that rule
 * may still carry a failed or refunded status, which is why scopeCollected()
 * filters on it.
 *
 * The table is a delete-protected, correction-controlled ledger: no payment
 * is deleted, its source never changes, and a gateway payment is never
 * updated. A manual payment changes only through the approved correction
 * workflow. The database enforces this with triggers
 * (add_ledger_triggers_to_payments_table); the hooks below say the same thing
 * earlier and more clearly.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'billing_id',
        'billing_revision_id',
        'payment_attempt_id',
        'amount_paid',
        'payment_method',
        'reference_number',
        'status',
        'source',
        'provider',
        'recorded_by',
        'payment_date',
    ];

    protected $attributes = [
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'source' => PaymentSource::class,
            'amount_paid' => 'decimal:2',
            'payment_date' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Payment $payment): void {
            if ($payment->isDirty('source')) {
                throw new LogicException('A payment\'s source cannot change.');
            }

            if ($payment->source === PaymentSource::Gateway) {
                throw new LogicException('A gateway-confirmed payment cannot be updated.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A recorded payment cannot be deleted.');
        });
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class, 'billing_id');
    }

    /**
     * The frozen statement this payment was made against. Null for payments recorded before revisions existed.
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(BillingRevision::class, 'billing_revision_id');
    }

    /**
     * The gateway checkout that confirmed this payment. Null for a manual payment.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    /**
     * Every receipt issued for this payment, voided ones included.
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class)->orderBy('id');
    }

    /**
     * The staff member who recorded a manual payment.
     *
     * Null for a gateway payment, and for a manual one recorded before the
     * recorder was stored and absent from the audit trail.
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Limit the query to payments that actually collected money.
     *
     * Summing a statement's settlements must never include a failed or
     * refunded attempt, so the filter lives here rather than being retyped at
     * each call site.
     */
    public function scopeCollected(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Completed);
    }
}
