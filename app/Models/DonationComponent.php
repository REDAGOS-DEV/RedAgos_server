<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A component the laboratory separated a donation into, and how many bags of it.
 *
 * This is the declaration blood-unit intake is constrained to: inventory may
 * record up to `quantity` units of this component for this donation and no
 * more, so a bag cannot be booked in for a component the laboratory never
 * produced.
 */
class DonationComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'donation_id',
        'component_id',
        'quantity',
        'declared_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'component_id');
    }

    /**
     * The staff member who declared this component breakdown.
     */
    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }
}
