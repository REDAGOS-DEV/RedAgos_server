<?php

namespace App\Models;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestSource;
use App\Enums\UrgencyLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Patient Transfusion Request: one patient's overall blood requirement.
 *
 * The hospital blood bank records what the patient needs — five units of O+
 * packed cells, two of platelets — and asks one or more blood centres for
 * shares of it. Each share is a facility allocation: a BloodRequest addressed
 * to one centre, which that centre approves (reserving real bags) or rejects
 * on its own. Asking a centre is never the same as it agreeing.
 *
 * What this record says about progress — approved, fulfilled, remaining, still
 * unallocated — is derived from its allocations by TransfusionRequestResolver,
 * never stored. Its status is derived the same way, except `cancelled`, which
 * is the hospital's decision.
 *
 * Three words that must not be confused:
 *  - TransfusionRequest  — "Patient Transfusion Request" (PTR reference);
 *  - BloodRequest        — "Facility Allocation" (RQ reference), one centre's share;
 *  - RequestAllocation   — "reserved unit", one bag held against an allocation line.
 */
class TransfusionRequest extends Model
{
    use HasPatient;

    /**
     * Status, closure and cancellation are deliberately absent: they are
     * derived or decided through TransfusionRequestService, never assigned.
     */
    protected $fillable = [
        'reference_number',
        'facility_id',
        'requested_by',
        'recorded_by',
        'request_source',
        'patient_surname',
        'patient_first_name',
        'patient_middle_name',
        'patient_age',
        'patient_sex',
        'blood_type_id',
        'urgency_level',
        'internal_stock_checked_at',
        'request_date',
    ];

    /**
     * Mirrors the column defaults; Eloquent does not read them back on insert.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'request_source' => 'blood_bank_portal',
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => BloodRequestStatus::class,
            'urgency_level' => UrgencyLevel::class,
            'request_source' => RequestSource::class,
            'patient_age' => 'integer',
            'internal_stock_checked_at' => 'immutable_datetime',
            'request_date' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * The hospital blood bank whose patient this is: the requester.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * The hospital staff member who raised it. Null on a walk-in.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The blood-centre staff member who entered a walk-in.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function bloodType(): BelongsTo
    {
        return $this->belongsTo(BloodType::class);
    }

    /**
     * What the patient needs, one line per component.
     */
    public function items(): HasMany
    {
        return $this->hasMany(TransfusionRequestItem::class);
    }

    /**
     * The shares of this requirement asked of individual centres.
     *
     * Named for what they are to the hospital. Each is a BloodRequest, whose
     * own allocations() are the bags reserved against it — a different thing,
     * which is why this is not called allocations().
     */
    public function facilityAllocations(): HasMany
    {
        return $this->hasMany(BloodRequest::class, 'transfusion_request_id')->orderBy('id');
    }

    /**
     * Everything that happened to the requirement and to each of its allocations, oldest first.
     */
    public function events(): HasMany
    {
        return $this->hasMany(BloodRequestEvent::class, 'transfusion_request_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Get the total units the patient needs across every component.
     */
    protected function quantity(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->items->sum('quantity'));
    }

    /**
     * Determine whether nothing more can come of this requirement.
     *
     * Fulfilled and cancelled say so outright. A partially fulfilled
     * requirement whose remaining lines the hospital closed says so through
     * closed_at, keeping the `partial` status that records what was supplied.
     */
    public function isClosed(): bool
    {
        return in_array($this->status, [BloodRequestStatus::Fulfilled, BloodRequestStatus::Cancelled], true)
            || $this->closed_at !== null;
    }

    public function scopeRaisedBy(Builder $query, int $facilityId): Builder
    {
        return $query->where('facility_id', $facilityId);
    }

    /**
     * Limit the query to requirements that can still be allocated or supplied.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                BloodRequestStatus::Pending->value,
                BloodRequestStatus::Processing->value,
                BloodRequestStatus::Partial->value,
            ])
            ->whereNull('closed_at');
    }
}
