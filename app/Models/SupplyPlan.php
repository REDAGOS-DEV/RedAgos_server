<?php

namespace App\Models;

use App\Enums\HandoverMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a blood centre will meet one replenishment order: picked up or delivered, and when.
 *
 * What each line will get is the line's own committed_quantity; this row is
 * the hand-over. Written only through SupplyPlanService, which checks the
 * order may still change and records who changed it.
 */
class SupplyPlan extends Model
{
    protected $fillable = [
        'request_id',
        'handover_method',
        'scheduled_at',
        'handover_address',
        'contact_name',
        'contact_phone',
        'notes',
        'planned_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'handover_method' => HandoverMethod::class,
            'scheduled_at' => 'immutable_datetime',
        ];
    }

    /**
     * The replenishment order this plan answers.
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'request_id');
    }

    /**
     * The centre staff member who first confirmed the order.
     */
    public function planner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'planned_by');
    }

    /**
     * The centre staff member who last changed it.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
