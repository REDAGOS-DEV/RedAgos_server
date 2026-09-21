<?php

namespace App\Models;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestPurpose;
use App\Enums\UrgencyLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 *
 * What was asked for lives on the lines, not here. A request can tick several
 * components on one DOH form, each with its own indication and unit count, so
 * component and quantity moved to blood_request_items and the quantity below
 * is a sum of them rather than a column.
 */
class BloodRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'facility_id',
        'target_facility_id',
        'requested_by',
        'request_purpose',
        'patient_surname',
        'patient_first_name',
        'patient_middle_name',
        'patient_age',
        'patient_sex',
        'blood_type_id',
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
            'request_purpose' => RequestPurpose::class,
            'patient_age' => 'integer',
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

    /**
     * The components this request asks for, one line each.
     */
    public function items(): HasMany
    {
        return $this->hasMany(BloodRequestItem::class, 'request_id');
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
     * Get the total units asked for across every line.
     *
     * Derived rather than stored, for the same reason inventory summaries are
     * derived from blood units: a stored copy would be a second source of truth
     * and would drift the moment a line changed. Callers that read this over a
     * collection should eager-load `items`, which BloodRequestRepository does.
     */
    protected function quantity(): Attribute
    {
        return Attribute::get(
            fn (): int => (int) $this->items->sum('quantity')
        );
    }

    /**
     * Get the patient's name as the request form prints it, if there is one.
     */
    public function patientFullName(): ?string
    {
        if ($this->patient_surname === null && $this->patient_first_name === null) {
            return null;
        }

        $given = trim(implode(' ', array_filter([
            $this->patient_first_name,
            $this->patient_middle_name,
        ])));

        $surname = mb_strtoupper((string) $this->patient_surname);

        return $given === '' ? $surname : "{$surname}, {$given}";
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
