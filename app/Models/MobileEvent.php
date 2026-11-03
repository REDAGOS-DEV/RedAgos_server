<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'expected_participants',
        'assigned_staff',
        'announcement',
        'published_at',
        'registration_closed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'max_capacity' => 'integer',
            'expected_participants' => 'integer',
            'published_at' => 'datetime',
            'registration_closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    /**
     * The staff member who cancelled the drive.
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(DonationAppointment::class, 'event_id');
    }

    /**
     * Limit the query to drives that have not been cancelled.
     */
    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /**
     * Limit the query to drives a donor can book: published, not cancelled and
     * with registration open. The date and capacity are the caller's to check.
     */
    public function scopeOpenForRegistration(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->whereNull('cancelled_at')
            ->whereNull('registration_closed_at');
    }

    /**
     * Whether the drive will not happen.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Whether donors can see the drive.
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Whether the drive's day is over.
     */
    public function hasPassed(): bool
    {
        return $this->event_date->isPast() && ! $this->event_date->isToday();
    }

    /**
     * Whether the drive takes new bookings, leaving capacity aside: published,
     * not cancelled, registration not closed, and its day not over.
     */
    public function acceptsRegistrations(): bool
    {
        return $this->isPublished()
            && ! $this->isCancelled()
            && $this->registration_closed_at === null
            && ! $this->hasPassed();
    }

    /**
     * The drive's lifecycle state, derived rather than stored.
     *
     * One vocabulary for both audiences: the donor catalogue lists only drives
     * open for registration, so it only ever sees Full, Open or Upcoming, while
     * the blood centre's management list also needs Draft, Completed and
     * Cancelled. Whether registration is closed is reported apart, beside this.
     *
     * Expects the `registered` count from withCount(); falls back to a query so
     * a bare model cannot silently report a drive as empty.
     */
    public function status(): string
    {
        if ($this->isCancelled()) {
            return 'Cancelled';
        }

        // Before Draft: a drive never published can still be held, its donors
        // typed in afterwards from the paper sheets.
        if ($this->hasPassed()) {
            return 'Completed';
        }

        if (! $this->isPublished()) {
            return 'Draft';
        }

        $registered = $this->registered ?? $this->appointments()->active()->count();

        if ($this->max_capacity !== null && (int) $registered >= $this->max_capacity) {
            return 'Full';
        }

        return $this->event_date->isToday() ? 'Open' : 'Upcoming';
    }
}
