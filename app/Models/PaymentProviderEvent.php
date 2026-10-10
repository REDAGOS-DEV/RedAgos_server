<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One webhook delivery from the payment provider, kept to the minimum reconciliation needs.
 *
 * No raw body, only its hash; summary holds status, amount, currency and
 * references. The server never acts on this row's word: it re-fetches the
 * session from the provider first.
 */
class PaymentProviderEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider',
        'event_type',
        'body_hash',
        'dedupe_key',
        'provider_object_id',
        'payment_attempt_id',
        'token_valid',
        'summary',
        'outcome',
        'error',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'token_valid' => 'boolean',
            'summary' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }
}
