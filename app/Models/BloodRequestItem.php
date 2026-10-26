<?php

namespace App\Models;

use App\Enums\IndicationCode;
use App\Enums\LineClosureReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One component asked for on a blood request, with the indication claimed for it.
 *
 * The DOH form is filled in per component: each one ticked carries its own
 * clinical criterion and its own unit count, and a request for packed cells and
 * platelets is one sheet of paper with two lines, not two requests. This row is
 * that line.
 *
 * On a facility allocation, the line is the part of a patient's requirement
 * line (TransfusionRequestItem) asked of this one centre.
 *
 * The line names its blood type. On every request but a weekly one it is the
 * request's own; a weekly request restocks several types in one order, so its
 * lines differ, and stock is held for each line from its own type's shelf.
 */
class BloodRequestItem extends Model
{
    use HasFactory, HasIndication;

    /**
     * The closure columns are deliberately absent. Closing a line is a decision
     * with an owner, made through RequestLineCloser, so no mass-assignment path
     * may reach it.
     */
    protected $fillable = [
        'request_id',
        'transfusion_request_item_id',
        'blood_type_id',
        'component_id',
        'quantity',
        'indication_code',
        'indication_other',
    ];

    protected function casts(): array
    {
        return [
            'indication_code' => IndicationCode::class,
            'quantity' => 'integer',
            'closed_at' => 'immutable_datetime',
            'closure_reason' => LineClosureReason::class,
        ];
    }

    /**
     * The patient requirement line this allocation line is a share of.
     */
    public function transfusionRequestItem(): BelongsTo
    {
        return $this->belongsTo(TransfusionRequestItem::class, 'transfusion_request_item_id');
    }

    /**
     * The staff member who closed the remainder of this line.
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * The request this line belongs to.
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'request_id');
    }

    /**
     * The blood type this line asks for.
     */
    public function bloodType(): BelongsTo
    {
        return $this->belongsTo(BloodType::class);
    }

    /**
     * The component this line asks for.
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'component_id');
    }

    /**
     * Every unit ever held against this line, including released holds.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(RequestAllocation::class, 'request_item_id');
    }
}
