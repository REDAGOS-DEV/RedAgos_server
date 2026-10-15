<?php

namespace App\Models;

use Database\Factories\StockThresholdFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One facility's stock levels for a blood type and component.
 *
 * `minimum_units` is the critical level a low-stock alert is judged against;
 * `par_units` is the stock the facility aims to keep. Either may be null, but
 * never both. See the migrations for why no count is stored: it is always
 * derived from the units.
 */
class StockThreshold extends Model
{
    /** @use HasFactory<StockThresholdFactory> */
    use HasFactory;

    protected $fillable = [
        'facility_id',
        'blood_type_id',
        'component_id',
        'minimum_units',
        'par_units',
        'alerts_enabled',
        'alerted_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'minimum_units' => 'integer',
            'par_units' => 'integer',
            'alerts_enabled' => 'boolean',
            'alerted_at' => 'datetime',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function bloodType(): BelongsTo
    {
        return $this->belongsTo(BloodType::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'component_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Limit the query to one facility's thresholds.
     */
    public function scopeForFacility(Builder $query, int $facilityId): Builder
    {
        return $query->where('stock_thresholds.facility_id', $facilityId);
    }

    /**
     * Limit the query to thresholds on components that have not been retired.
     *
     * `whereHas` applies BloodComponent's SoftDeletes scope, so a threshold
     * left behind on a retired component is invisible to the status, the sweep
     * and the facilities-to-sweep query alike, instead of alerting on a row no
     * screen can show or edit.
     */
    public function scopeOnActiveComponents(Builder $query): Builder
    {
        return $query->whereHas('component');
    }
}
