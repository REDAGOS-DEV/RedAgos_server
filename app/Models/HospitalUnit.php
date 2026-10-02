<?php

namespace App\Models;

use App\Enums\HospitalUnitStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A bag in a hospital blood bank's custody, from receipt until it is transfused or leaves the shelf.
 *
 * Created when the hospital confirms receipt of a dispatched hold, and only
 * then — RedAgos knows a hospital's stock only as far as it delivered it.
 *
 * The bag's own facts (type, component, volume, expiry) are read from the
 * centre's blood_units row through bloodUnit(), never copied: there is one
 * physical bag, so there is one row that describes it. This row says only
 * where the bag stands at the hospital.
 */
class HospitalUnit extends Model
{
    use HasFactory;

    /**
     * Status and its timestamps are written by HospitalInventoryService and
     * the hospital sweeps, never assigned from a request.
     */
    protected $fillable = [
        'facility_id',
        'unit_id',
        'request_allocation_id',
        'status',
    ];

    /**
     * Mirrors the column default; Eloquent does not read it back on insert.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'available',
    ];

    protected function casts(): array
    {
        return [
            'status' => HospitalUnitStatus::class,
            'expired_at' => 'immutable_datetime',
            'discarded_at' => 'immutable_datetime',
        ];
    }

    /**
     * The hospital blood bank holding the bag.
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * The physical bag, as the centre that collected it recorded it.
     */
    public function bloodUnit(): BelongsTo
    {
        return $this->belongsTo(BloodUnit::class, 'unit_id');
    }

    /**
     * The dispatched hold whose receipt put the bag in this hospital's custody.
     */
    public function requestAllocation(): BelongsTo
    {
        return $this->belongsTo(RequestAllocation::class);
    }

    /**
     * Every tag ever placed on this bag, including the ones that ended.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(UnitTag::class);
    }

    /**
     * The tag that currently holds this bag for a patient, if any.
     *
     * At most one can exist: a partial unique index over hospital_unit_id
     * restricted to the active statuses enforces it, so this is a hasOne
     * rather than "the latest of several".
     */
    public function activeTag(): HasOne
    {
        return $this->hasOne(UnitTag::class)->active();
    }

    /**
     * The most recent tag on this bag, active or not.
     *
     * A bag pending return is explained by its last tag, which has already
     * ended.
     */
    public function latestTag(): HasOne
    {
        return $this->hasOne(UnitTag::class)->latestOfMany();
    }

    /**
     * The staff member who discarded the bag.
     */
    public function discardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discarded_by');
    }

    /**
     * Limit the query to one hospital's custody.
     *
     * Qualified, because the listing joins blood_units, which has a
     * facility_id of its own — the centre's.
     */
    public function scopeForFacility(Builder $query, int $facilityId): Builder
    {
        return $query->where('hospital_units.facility_id', $facilityId);
    }

    /**
     * Order first-expiring-first by the bag's own expiry date.
     *
     * FEFO holds at the hospital as at the centre. The date lives on
     * blood_units, so it is ordered by a correlated subquery rather than a
     * join, which keeps this table's columns unambiguous for the caller.
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query
            ->orderBy(
                BloodUnit::query()
                    ->select('expiry_date')
                    ->whereColumn('blood_units.id', 'hospital_units.unit_id')
            )
            ->orderBy('hospital_units.unit_id');
    }
}
