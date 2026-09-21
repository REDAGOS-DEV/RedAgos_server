<?php

namespace App\Models;

use App\Enums\IndicationCode;
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
 */
class BloodRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
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
        ];
    }

    /**
     * The request this line belongs to.
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'request_id');
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

    /**
     * Get the indication as it should read on paper and on screen.
     *
     * An "Others" code carries no criterion of its own — the requester's own
     * words are the indication — so those are shown instead of the placeholder
     * text the enum holds for them.
     */
    public function indicationText(): ?string
    {
        if ($this->indication_code === null) {
            return null;
        }

        return $this->indication_code->triggersReview() && $this->indication_other !== null
            ? $this->indication_other
            : $this->indication_code->description();
    }
}
