<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a centre's own discounts, offered at the billing counter beside the
 * government subsidy (project owner, 2026-11-10).
 *
 * A percentage of the bill, or a fixed peso amount off it. Switched off
 * rather than deleted: a bill it was given to keeps its own copy of the name,
 * kind and value (billings.discount_*), so neither an edit nor switching it
 * off ever changes a bill already discounted.
 */
class BillingDiscount extends Model
{
    public const PERCENTAGE = 'percentage';

    public const FIXED = 'fixed';

    public const KINDS = [self::PERCENTAGE, self::FIXED];

    protected $fillable = [
        'facility_id', 'name', 'kind', 'value', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** "20% off" or "₱500.00 off", as the counter and the list show it. */
    public function describe(): string
    {
        return $this->kind === self::PERCENTAGE
            ? rtrim(rtrim(number_format((float) $this->value, 2, '.', ''), '0'), '.').'% off'
            : '₱'.number_format((float) $this->value, 2).' off';
    }
}
