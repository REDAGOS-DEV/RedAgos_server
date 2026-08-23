<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Facility extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'facility_type_id',
        'name',
        'address',
        'operating_hours',
        'is_accepting_donations',
        'slot_capacity',
        'slot_interval_minutes',
        'slots_start_at',
        'slots_end_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_accepting_donations' => true,
        'slot_capacity' => 4,
        'slot_interval_minutes' => 30,
    ];

    protected function casts(): array
    {
        return [
            'is_accepting_donations' => 'boolean',
            'slot_capacity' => 'integer',
            'slot_interval_minutes' => 'integer',
        ];
    }

    public function facilityType(): BelongsTo
    {
        return $this->belongsTo(FacilityType::class);
    }

    public function mobileEvents(): HasMany
    {
        return $this->hasMany(MobileEvent::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(DonationAppointment::class);
    }

    /**
     * Limit the query to facilities currently open to donor bookings.
     */
    public function scopeAcceptingDonations(Builder $query): Builder
    {
        return $query->where('is_accepting_donations', true);
    }
}
