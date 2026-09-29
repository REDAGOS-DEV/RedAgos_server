<?php

namespace App\Models;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestPurpose;
use App\Enums\RequestSource;
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
 *
 * For a Patient Transfusion this row is a *facility allocation*: the share of
 * a patient's requirement (TransfusionRequest) asked of one centre, which that
 * centre approves or rejects on its own. Not to be confused with
 * RequestAllocation, which is one reserved bag. A replenishment request has no
 * requirement above it.
 */
class BloodRequest extends Model
{
    use HasFactory, HasPatient;

    protected $fillable = [
        'reference_number',
        'transfusion_request_id',
        'facility_id',
        'target_facility_id',
        'requested_by',
        'recorded_by',
        'request_purpose',
        'request_source',
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
        'closed_at',
    ];

    /**
     * Mirrors the column default, so a request built in PHP carries its source
     * before it is ever read back — Eloquent does not fetch defaults on insert.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'request_source' => 'blood_bank_portal',
    ];

    protected function casts(): array
    {
        return [
            'status' => BloodRequestStatus::class,
            'urgency_level' => UrgencyLevel::class,
            'request_purpose' => RequestPurpose::class,
            'request_source' => RequestSource::class,
            'patient_age' => 'integer',
            'request_date' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
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
     *
     * Null on a walk-in: nobody at the hospital submitted it. The hospital
     * confirmed it by phone and a blood-centre staff member entered it, who is
     * recorder() below.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The blood-centre staff member who entered a walk-in request.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The patient requirement this allocation is a share of, for a transfusion.
     */
    public function transfusionRequest(): BelongsTo
    {
        return $this->belongsTo(TransfusionRequest::class, 'transfusion_request_id');
    }

    /**
     * The representative and phone-verification record of a walk-in request.
     */
    public function walkIn(): HasOne
    {
        return $this->hasOne(BloodRequestWalkIn::class, 'request_id');
    }

    /**
     * Everything that has happened to this request, oldest first.
     */
    public function events(): HasMany
    {
        return $this->hasMany(BloodRequestEvent::class, 'request_id')
            ->orderBy('created_at')
            ->orderBy('id');
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
     * Determine whether nothing more may be done to fill this request.
     *
     * A terminal status says so outright. A partially fulfilled request whose
     * every remaining line was closed says so through closed_at, because it
     * keeps the `partial` status — what was actually supplied — while being
     * finished.
     */
    public function isClosed(): bool
    {
        return $this->status->isTerminal() || $this->closed_at !== null;
    }

    /**
     * Limit the query to requests that may still be acted on.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereIn('status', array_values(array_map(
                fn (BloodRequestStatus $status): string => $status->value,
                array_filter(BloodRequestStatus::cases(), fn (BloodRequestStatus $status): bool => ! $status->isTerminal())
            )))
            ->whereNull('closed_at');
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
