<?php

namespace App\Models;

use App\Enums\BloodUnitStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloodUnit extends Model
{
    use HasFactory;

    /**
     * The primary key is the number printed on the physical bag, not a
     * sequence, so Eloquent must not treat it as an incrementing integer.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'facility_id',
        'component_id',
        'blood_type_id',
        'donation_id',
        'storage_location',
        'expiry_date',
        'status',
        'discard_reason',
        'expired_at',
        'discarded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BloodUnitStatus::class,
            'expiry_date' => 'immutable_date',
            'expired_at' => 'immutable_datetime',
            'discarded_at' => 'immutable_datetime',
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

    public function bloodType(): BelongsTo
    {
        return $this->belongsTo(BloodType::class);
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * Limit the query to one facility's stock.
     *
     * Facility isolation is applied in the repository on every read, but having
     * it as a scope means a future caller cannot forget the column name.
     */
    public function scopeForFacility(Builder $query, int $facilityId): Builder
    {
        return $query->where('facility_id', $facilityId);
    }

    /**
     * Order first-expiring-first.
     *
     * FEFO is a business rule from the paper rather than a display preference,
     * so it lives on the model instead of being retyped per query. The id
     * tiebreak keeps the order stable when two units share an expiry date,
     * which matters for paginated listings.
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderBy('expiry_date')->orderBy('id');
    }
}
