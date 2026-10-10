<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A Payment Acknowledgement Receipt for one confirmed payment. Not a BIR official receipt.
 *
 * Everything printed is in the snapshot, frozen at issue. The only change the
 * database allows is one void — all three void fields at once — and never a
 * delete (create_payment_receipts_table). A corrected payment gets a new
 * receipt naming the one it replaces.
 */
class PaymentReceipt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_id',
        'issuing_facility_id',
        'receipt_number',
        'replaces_receipt_id',
        'issued_at',
        'issued_by',
        'snapshot',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'issued_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PaymentReceipt $receipt): void {
            $onlyVoidFields = array_diff(array_keys($receipt->getDirty()), ['voided_at', 'voided_by', 'void_reason']) === [];

            $voiding = $receipt->getOriginal('voided_at') === null
                && $receipt->voided_at !== null
                && $receipt->voided_by !== null
                && trim((string) $receipt->void_reason) !== ''
                && $onlyVoidFields;

            if (! $voiding) {
                throw new LogicException('A receipt can only be voided, once and completely.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A receipt cannot be deleted.');
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function issuingFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'issuing_facility_id');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_receipt_id');
    }

    /**
     * Limit the query to receipts that have not been voided.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /**
     * Determine whether the receipt has been voided.
     */
    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
