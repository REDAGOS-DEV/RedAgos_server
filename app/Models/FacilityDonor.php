<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One blood centre's own record of a donor: its staff's notes, whether the
 * centre registered them, and when it last reminded them. See the migration.
 */
class FacilityDonor extends Model
{
    protected $fillable = [
        'facility_id',
        'donor_id',
        'notes',
        'registered_here',
        'eligibility_reminded_at',
        'updated_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'registered_here' => false,
    ];

    protected function casts(): array
    {
        return [
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
