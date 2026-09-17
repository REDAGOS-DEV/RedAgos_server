<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One facility's own settings for a blood component.
 *
 * See the migration for why these two columns are held per facility rather
 * than on the shared `blood_components` catalogue.
 */
class FacilityBloodComponent extends Model
{
    protected $fillable = [
        'facility_id',
        'component_id',
        'shelf_life_days',
        'price',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'shelf_life_days' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'component_id');
    }

    /**
     * Whether this facility has a clinically approved shelf life for it.
     *
     * Expiry derivation must refuse to run without one rather than fall back to
     * a default, so callers check this instead of coalescing a null away.
     */
    public function hasShelfLife(): bool
    {
        return $this->shelf_life_days !== null;
    }
}
