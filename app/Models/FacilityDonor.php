<?php

namespace App\Models;

use App\Enums\FacilityDonorStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One blood centre's own record of a donor: their status there, its staff's
 * notes, and whether the centre registered them. See the migration.
 */
class FacilityDonor extends Model
{
    protected $fillable = [
        'facility_id',
        'donor_id',
        'status',
        'notes',
        'registered_here',
        'eligibility_reminded_at',
        'updated_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'registered_here' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => FacilityDonorStatus::class,
            'registered_here' => 'boolean',
            'eligibility_reminded_at' => 'datetime',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }
}
