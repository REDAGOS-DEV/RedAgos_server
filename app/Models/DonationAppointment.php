<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DonationAppointment extends Model
{
    use HasFactory;

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
        'status' => AppointmentStatus::Scheduled->value,
    ];

    protected function casts(): array
    {
        return [
            'appointment_datetime' => 'datetime',
            'status' => AppointmentStatus::class,
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
     * The donation opened against this booking, if the donor got that far.
     *
     * A collected visit and a deferred one both close the appointment as
     * `completed`; this is what tells them apart.
     */
    public function donation(): HasOne
    {
        return $this->hasOne(Donation::class, 'appointment_id')->latestOfMany();
    }

    /**
     * Limit the query to appointments that still occupy a slot.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AppointmentStatus::activeValues());
    }
}
