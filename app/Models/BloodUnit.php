<?php

namespace App\Models;

use App\Enums\BloodUnitStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

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
        'volume_ml',
        'blood_type_id',
        'donation_id',
        'direct_distribution_id',
        'storage_location',
        'expiry_date',
        'status',
        'discard_reason',
        'expired_at',
        'discarded_at',
        'released_at',
        'released_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => BloodUnitStatus::class,
            'expiry_date' => 'immutable_date',
            'expired_at' => 'immutable_datetime',
            'discarded_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'volume_ml' => 'integer',
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

    /**
     * Refuse a bag with no origin, or with two.
     *
     * A bag RedAgos collected traces to its donation; a bag a hospital received
     * from outside RedAgos traces to the delivery that brought it. Exactly one
     * of the two, always — a bag tracing to neither is untraceable, and one
     * tracing to both is lying about one of them. PostgreSQL holds the same
     * rule as a CHECK constraint; this holds it on every driver.
     */
    protected static function booted(): void
    {
        static::saving(function (BloodUnit $unit): void {
            // An existing row loaded with a column subset says nothing about
            // its origin, and an update that leaves both columns alone cannot
            // change it.
            if ($unit->exists && ! $unit->isDirty(['donation_id', 'direct_distribution_id'])) {
                return;
            }

            if (($unit->donation_id === null) === ($unit->direct_distribution_id === null)) {
                throw new LogicException('A blood unit must trace to exactly one origin: a donation or a direct distribution.');
            }
        });
    }

    /**
     * The donation the bag was collected from. Null for a bag received by direct distribution.
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * The delivery from outside RedAgos that brought the bag, if it was not collected here.
     */
    public function directDistribution(): BelongsTo
    {
        return $this->belongsTo(DirectDistribution::class);
    }

    /**
     * Determine whether the bag came from outside RedAgos rather than from a donation.
     */
    public function isDirectDistribution(): bool
    {
        return $this->direct_distribution_id !== null;
    }

    /**
     * The Inventory Control Officer who released this unit from quarantine.
     */
    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * Every hold ever placed on this unit, including ones given up.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(RequestAllocation::class, 'unit_id');
    }

    /**
     * The hold that currently claims this unit, if any.
     *
     * At most one can exist: a partial unique index over unit_id restricted to
     * the claiming statuses enforces it in the database, so this is a hasOne
     * rather than "the latest of several".
     */
    public function activeAllocation(): HasOne
    {
        return $this->hasOne(RequestAllocation::class, 'unit_id')->claiming();
    }

    /**
     * The hospital custody this bag entered on receipt, if it has been received.
     *
     * Read-only from the centre's point of view: the hospital's tags and
     * transfusion never write back to this row.
     */
    public function hospitalUnit(): HasOne
    {
        return $this->hasOne(HospitalUnit::class, 'unit_id');
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
