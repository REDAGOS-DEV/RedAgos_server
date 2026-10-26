<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TransactionCategory;
use App\Enums\TransactionChannel;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One money event on a bill, numbered and categorised: the billing journal.
 *
 * Written only through BillingLedger, inside the transaction that made the
 * change. `amount` is signed against the bill — positive raises what is owed,
 * negative settles it — and `balance_after` is the bill's balance once this
 * row is counted. Rows are never changed or deleted
 * (create_billing_transactions_table); the hooks below say so earlier.
 */
class BillingTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'transaction_number',
        'facility_id',
        'billing_id',
        'request_id',
        'category',
        'type',
        'channel',
        'payment_method',
        'amount',
        'balance_after',
        'payment_id',
        'payment_receipt_id',
        'billing_revision_id',
        'payment_attempt_id',
        'cash_session_id',
        'correction_request_id',
        'reverses_transaction_id',
        'reference',
        'note',
        'recorded_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => TransactionCategory::class,
            'type' => TransactionType::class,
            'channel' => TransactionChannel::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A billing transaction cannot be changed. Post a further one instead.');
        });

        static::deleting(function (): void {
            throw new LogicException('A billing transaction cannot be deleted.');
        });
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'request_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PaymentReceipt::class, 'payment_receipt_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(BillingRevision::class, 'billing_revision_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The transaction this one reverses, for a void.
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }
}
