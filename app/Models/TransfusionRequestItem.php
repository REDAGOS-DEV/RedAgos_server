<?php

namespace App\Models;

use App\Enums\IndicationCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One component a patient needs, and how many units in all.
 *
 * `quantity` is the overall requirement and is never changed. Each facility
 * allocation carries its share on a BloodRequestItem pointing back here, and
 * everything about progress is read from those lines.
 */
class TransfusionRequestItem extends Model
{
    use HasIndication;

    /**
     * The closure columns are deliberately absent: closing the rest of a
     * requirement line is the hospital's decision, made through
     * TransfusionRequestService.
     */
    protected $fillable = [
        'transfusion_request_id',
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
        ];
    }

    public function transfusionRequest(): BelongsTo
    {
        return $this->belongsTo(TransfusionRequest::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'component_id');
    }

    /**
     * The shares of this line asked of individual centres.
     */
    public function allocationLines(): HasMany
    {
        return $this->hasMany(BloodRequestItem::class, 'transfusion_request_item_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
