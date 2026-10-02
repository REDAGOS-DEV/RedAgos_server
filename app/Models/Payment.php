<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One settlement attempt against a statement.
 *
 * Attempts rather than receipts: an electronic payment can fail or be reversed,
 * and a statement that is still unpaid needs to be able to show why.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'billing_id',
        'amount_paid',
        'payment_method',
        'reference_number',
        'status',
        'payment_date',
    ];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount_paid' => 'decimal:2',
            'payment_date' => 'immutable_datetime',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class, 'billing_id');
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
