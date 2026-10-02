<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MobileEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'facility_id',
        'created_by',
        'name',
        'location',
        'event_date',
        'start_time',
        'end_time',
        'max_capacity',
        'assigned_staff',
        'announcement',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'max_capacity' => 'integer',
            // Deliberately not 'datetime': these are wall-clock times with no
            // date of their own, and casting them would hand callers a full
            // timestamp on today's date rather than the 'HH:MM' they stored.
            'start_time' => 'string',
            'end_time' => 'string',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(DonationAppointment::class, 'event_id');
    }

    /**
     * The drive's lifecycle state, derived rather than stored.
     *
     * One vocabulary for both audiences: the donor catalogue never shows a past
     * drive, so it only ever sees Full, Open or Upcoming, while the blood
     * centre's management list also needs Completed.
     *
     * Expects the `registered` count from withCount(); falls back to a query so
     * a bare model cannot silently report a drive as empty.
     */
    public function status(): string
    {
        if ($this->event_date->isPast() && ! $this->event_date->isToday()) {
            return 'Completed';
        }

        $registered = $this->registered ?? $this->appointments()->active()->count();

        if ($this->max_capacity !== null && (int) $registered >= $this->max_capacity) {
            return 'Full';
        }

        return $this->event_date->isToday() ? 'Open' : 'Upcoming';
    }
}
