<?php

namespace App\Models;

use App\Enums\BloodRequestStatus;
use App\Enums\UrgencyLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A hospital blood bank's request for blood from a blood service facility.
 *
 * Two facilities are involved and they are never interchangeable: facility_id
 * is who asked, target_facility_id is who was asked. Every scope and relation
 * here names which one it means, because a query that confuses them would show
 * one facility another's requests.
 */
class BloodRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'facility_id',
        'target_facility_id',
        'requested_by',
        'blood_type_id',
        'component_id',
        'quantity',
        'urgency_level',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'request_date',
        'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BloodRequestStatus::class,
            'urgency_level' => UrgencyLevel::class,
            'quantity' => 'integer',
            'request_date' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
        ];
    }

    /**
     * The facility that raised the request.
     */
    public function requestingFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * The facility the request was addressed to and which fulfils it.
     */
    public function targetFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'target_facility_id');
    }

    /**
     * The blood-bank staff member who submitted the request.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The fulfilling staff member who approved or rejected the request.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function bloodType(): BelongsTo
    {
        return $this->belongsTo(BloodType::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'component_id');
    }

    /**
     * Every blood unit ever held for this request, including released holds.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(RequestAllocation::class, 'request_id');
    }

    /**
     * The single statement raised against this request, if one has been.
     *
     * hasOne rather than hasMany because billings.request_id is unique: a
     * request is billed once or not at all.
     */
    public function billing(): HasOne
    {
        return $this->hasOne(Billing::class, 'request_id');
    }

    /**
     * Limit the query to requests raised by one facility.
     */
    public function scopeRaisedBy(Builder $query, int $facilityId): Builder
    {
        return $query->where('facility_id', $facilityId);
    }

    /**
     * Limit the query to requests addressed to one facility.
     *
     * This is the incoming queue. Kept as a scope so a caller reaching for
     * "requests for my facility" cannot reach for facility_id by mistake and
     * silently list the wrong side of the workflow.
     */
    public function scopeAddressedTo(Builder $query, int $facilityId): Builder
    {
        return $query->where('target_facility_id', $facilityId);
    }

    /**
     * Order emergencies first, then oldest first within each urgency.
     *
     * Urgency is ordered explicitly rather than alphabetically: 'emergency'
     * happens to sort before 'routine', but relying on that would break the
     * moment a third level is added.
     */
    public function scopeTriaged(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN urgency_level = ? THEN 0 ELSE 1 END', [UrgencyLevel::Emergency->value])
            ->orderBy('request_date');
    }
}
