<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One blood unit held against one blood request.
 *
 * This row is where the chain of custody lives. It records that a specific bag
 * was promised to a specific request, when it left the facility, and when the
 * receiving facility confirmed it arrived — three separate moments, because a
 * unit that has been dispatched has not necessarily been received, and only the
 * receiving facility can say otherwise.
 *
 * A partial unique index over unit_id, restricted to the claiming statuses, is
 * what stops the same bag being promised twice. See the migration
 * relax_request_allocation_unit_uniqueness for why it is partial rather than
 * global.
 */
class RequestAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'request_item_id',
        'unit_id',
        'allocated_at',
        'allocated_by',
        'status',
        'released_at',
        'released_by',
        'handed_to',
        'received_at',
        'received_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => AllocationStatus::class,
            'allocated_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'request_id');
    }

    /**
     * The request line this allocation answers.
     *
     * request_id alone cannot say which component a hold is for once a request
     * asks for more than one, so every hold names its line.
     */
    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(BloodRequestItem::class, 'request_item_id');
    }

    /**
     * The blood unit this allocation holds.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(BloodUnit::class, 'unit_id');
    }

    /**
     * The staff member who reserved the unit for the request.
     */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    /**
     * The staff member who dispatched the unit.
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * The requesting-facility staff member who confirmed the unit arrived.
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Limit the query to allocations that still lay claim to their unit.
     *
     * Cancelled holds are excluded, which is exactly what makes a cancelled
     * unit allocatable again. Every availability and duplicate check reads
     * through here so the definition of "claimed" lives in one place.
     */
    public function scopeClaiming(Builder $query): Builder
    {
        return $query->whereIn('status', AllocationStatus::claimingValues());
    }

    /**
     * Limit the query to holds that have been dispatched.
     */
    public function scopeReleased(Builder $query): Builder
    {
        return $query->where('status', AllocationStatus::Released);
    }

    /**
     * Limit the query to dispatched holds the requester has confirmed.
     */
    public function scopeReceived(Builder $query): Builder
    {
        return $query->whereNotNull('received_at');
    }
}
