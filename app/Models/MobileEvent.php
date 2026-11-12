<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MobileEvent extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Every drive gets its registration link's token when it is saved. The
     * link answers only while the drive is published.
     */
    protected static function booted(): void
    {
        static::creating(function (MobileEvent $drive): void {
            $drive->registration_token ??= Str::random(32);
        });
    }

    protected $fillable = [
        'facility_id',
        'created_by',
        'name',
        'location',
        'event_date',
        'start_time',
        'end_time',
        'assigned_staff',
        'announcement',
        'published_at',
        'registration_closed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'registration_token',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
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
     * The people put on the drive's list through its registration link.
     */
    public function preRegistrations(): HasMany
    {
        return $this->hasMany(DrivePreRegistration::class, 'mobile_event_id');
    }

    /**
     * The partner batches sent through the drive's registration link.
     */
    public function partnerSubmissions(): HasMany
    {
        return $this->hasMany(DrivePartnerSubmission::class, 'mobile_event_id');
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
     * with registration open. The date is the caller's to check. There is no
     * booking limit (project owner, 2026-11-12).
     */
    public function scopeOpenForRegistration(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->whereNull('cancelled_at')
            ->whereNull('registration_closed_at');
    }

    /**
     * Load the two halves of the pre-registered donors count (project owner,
     * 2026-11-12): the bookings made ahead of the drive that still stand, and
     * the people on its registration-link list not yet tied to a booking.
     * Read the total with preRegisteredCount().
     */
    public function scopeWithPreRegisteredCounts(Builder $query): Builder
    {
        return $query->withCount([
            'appointments as booked_ahead' => fn (Builder $q) => $q->preRegistered(),
            'preRegistrations as listed_only' => fn (Builder $q) => $q->onList()->whereNull('appointment_id'),
        ]);
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
     * Whether the drive takes new bookings: published, not cancelled,
     * registration not closed, and its day not over.
     */
    public function acceptsRegistrations(): bool
    {
        return $this->isPublished()
            && ! $this->isCancelled()
            && $this->registration_closed_at === null
            && ! $this->hasPassed();
    }

    /**
     * Everyone who said ahead of the drive that they are coming: bookings
     * still standing plus the link's list not yet booked. One person is never
     * counted twice, since a list entry tied to a booking is counted by the
     * booking. Expects withPreRegisteredCounts(); falls back to queries.
     */
    public function preRegisteredCount(): int
    {
        $booked = $this->booked_ahead ?? $this->appointments()->preRegistered()->count();
        $listed = $this->listed_only ?? $this->preRegistrations()->onList()->whereNull('appointment_id')->count();

        return (int) $booked + (int) $listed;
    }

    /**
     * The drive's lifecycle state, derived rather than stored.
     *
     * One vocabulary for both audiences: the donor catalogue lists only drives
     * open for registration, so it only ever sees Open or Upcoming, while the
     * blood centre's management list also needs Draft, Completed and
     * Cancelled. Whether registration is closed is reported apart, beside this.
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

        return $this->event_date->isToday() ? 'Open' : 'Upcoming';
    }
}
