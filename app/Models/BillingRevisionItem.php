<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One frozen line of an issued statement: a component, how many units, at what price.
 *
 * The component name and unit price are copied at issue, so renaming a
 * component or changing its price later leaves the issued statement as it was.
 */
class BillingRevisionItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'billing_revision_id',
        'request_item_id',
        'component_id',
        'component_name',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A line of an issued statement cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A line of an issued statement cannot be deleted.');
        });
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(BillingRevision::class, 'billing_revision_id');
    }
}
