<?php

namespace App\Models;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One thing that happened to a blood request, as its history shows it.
 *
 * Append-only: written by BloodRequestHistory and never changed. The lines
 * column is the per-component snapshot at that moment, so what was requested,
 * reserved, fulfilled, received and still remaining can be read back exactly as
 * it stood after each step.
 */
class BloodRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'blood_request_events';

    protected $fillable = [
        'request_id',
        'transfusion_request_id',
        'request_item_id',
        'event',
        'from_status',
        'to_status',
        'actor_id',
        'actor_facility_id',
        'related_request_id',
        'lines',
        'unit_ids',
        'meta',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'event' => RequestEventType::class,
            'from_status' => BloodRequestStatus::class,
            'to_status' => BloodRequestStatus::class,
            'lines' => 'array',
            'unit_ids' => 'array',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A blood request event is history and cannot be changed.');
        });
    }

    /**
     * The facility allocation this happened to. Null for an event on the
     * patient requirement itself — created, allocations added, cancelled.
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'request_id');
    }

    /**
     * The patient requirement this event belongs to, for a transfusion.
     */
    public function transfusionRequest(): BelongsTo
    {
        return $this->belongsTo(TransfusionRequest::class, 'transfusion_request_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(BloodRequestItem::class, 'request_item_id');
    }

    /**
     * The staff member who did this. Null for the scheduler.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actorFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'actor_facility_id');
    }

    /**
     * The other request this event concerns: a follow-up, or its parent.
     */
    public function relatedRequest(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'related_request_id');
    }
}
