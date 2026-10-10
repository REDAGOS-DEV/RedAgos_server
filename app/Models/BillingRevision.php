<?php

namespace App\Models;

use App\Enums\BillingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One issued Statement of Account: a frozen snapshot of a statement.
 *
 * The billing row is the live draft; a revision is what a payer was shown at
 * one moment — its lines, its total, what had been collected and what was due.
 * It never changes. The database refuses any update or delete
 * (create_billing_revisions_table); the hooks below say so earlier.
 */
class BillingRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'billing_id',
        'revision_number',
        'document_number',
        'issuing_facility_id',
        'payer_facility_id',
        'currency',
        'total_amount',
        'collected_at_issue',
        'amount_due',
        'statement_only',
        'billing_status',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'total_amount' => 'decimal:2',
            'collected_at_issue' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'statement_only' => 'boolean',
            'billing_status' => BillingStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('An issued statement revision cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('An issued statement revision cannot be deleted.');
        });
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingRevisionItem::class)->orderBy('id');
    }

    public function issuingFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'issuing_facility_id');
    }

    public function payerFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'payer_facility_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
