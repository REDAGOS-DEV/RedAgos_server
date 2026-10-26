<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A facility-owned billing relief programme, scheduled independently of an
 * individual patient's statement. Programmes are retained when disabled so
 * the centre can always explain what was offered on a particular date.
 */
class BillingProgram extends Model
{
    public const GOVERNMENT_SUBSIDY = 'government_subsidy';

    public const PERCENTAGE_DISCOUNT = 'percentage_discount';

    public const FIXED_DISCOUNT = 'fixed_discount';

    protected $fillable = [
        'facility_id', 'name', 'kind', 'value', 'effective_from', 'effective_to',
        'is_enabled', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'is_enabled' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
