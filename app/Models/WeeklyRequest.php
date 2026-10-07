<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hospital blood bank's scheduled restock, sent to one centre on one of its request days.
 *
 * A header over ordinary replenishment requests, one per blood type: a blood
 * request carries a single blood type, and a weekly restock covers several.
 * Each is approved, reserved, dispatched and received by the centre exactly
 * as any replenishment is, except that it goes out in one delivery and
 * whatever was not supplied is closed as unavailable when it does
 * (FulfillmentService::release).
 */
class WeeklyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'facility_id',
        'target_facility_id',
        'request_day',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'request_day' => 'immutable_date',
        ];
    }

    /**
     * The hospital blood bank that sent it.
     */
    public function requestingFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * The blood centre it was sent to.
     */
    public function targetFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'target_facility_id');
    }

    /**
     * The staff member who sent it.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Its replenishment requests, one per blood type.
     */
    public function bloodRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class)->orderBy('blood_type_id');
    }

    /**
     * Limit the query to weekly requests one hospital sent.
     */
    public function scopeRaisedBy(Builder $query, int $facilityId): Builder
    {
        return $query->where('facility_id', $facilityId);
    }
}
