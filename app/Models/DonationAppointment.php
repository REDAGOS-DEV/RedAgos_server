<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationAppointment extends Model
{
    use HasFactory;

    /**
     * Appointment states that still hold a slot.
     */
    public const ACTIVE_STATUSES = ['scheduled', 'confirmed'];

    protected $fillable = [
        'donor_id',
        'facility_id',
        'event_id',
        'appointment_datetime',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'scheduled',
    ];

    protected function casts(): array
    {
        return [
            'appointment_datetime' => 'datetime',
        ];
    }

    public function donorProfile(): BelongsTo
    {
        return $this->belongsTo(DonorProfile::class, 'donor_id', 'donor_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function mobileEvent(): BelongsTo
    {
        return $this->belongsTo(MobileEvent::class, 'event_id');
    }

    /**
     * Limit the query to appointments that still occupy a slot.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
