<?php

namespace App\Models;

use App\Enums\FacilityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

class Facility extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The approval trail is deliberately absent from this list.
     *
     * status, approved_at, approved_by, rejection_reason, resubmitted_at and
     * registration_contact_user_id decide whether an organisation may touch
     * real blood stock, so no mass-assignment path may reach them.
     * FacilityRepository and FacilityApprovalService set them by direct
     * assignment, from the authenticated administrator rather than the request.
     */
    protected $fillable = [
        'facility_type_id',
        'name',
        // A blood centre's bag-number prefix (SNBC-21627990-PRBC). Locked
        // once the centre has booked a bag: see booted().
        'abbreviation',
        'doh_license_number',
        'contact_person',
        'email',
        'phone',
        'address',
        'description',
        'operating_hours',
        'is_accepting_donations',
        'slot_capacity',
        'slot_interval_minutes',
        'slots_start_at',
        'slots_end_at',
        // The weekdays it takes bookings (0 = Monday) and the dates it is closed.
        'open_days',
        'closed_dates',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        // Fail closed. The column default is 'approved' so the facilities that
        // already existed before approval was introduced stay usable, but any
        // facility this application constructs starts unapproved unless a
        // service says otherwise. FacilityRepository is that service: a
        // Super Admin creating a facility approves it in the same breath.
        'status' => FacilityStatus::PendingApproval->value,
        'is_accepting_donations' => true,
        'slot_capacity' => 4,
        'slot_interval_minutes' => 30,
    ];

    protected function casts(): array
    {
        return [
            'status' => FacilityStatus::class,
            'approved_at' => 'datetime',
            'resubmitted_at' => 'datetime',
            'is_accepting_donations' => 'boolean',
            'slot_capacity' => 'integer',
            'slot_interval_minutes' => 'integer',
            'open_days' => 'array',
            'closed_dates' => 'array',
        ];
    }

    /**
     * Keep the abbreviation as it prints, and fixed once a bag carries it.
     *
     * Every bag a centre books is numbered from its abbreviation
     * (BagNumbers), so changing it after the first bag would leave the
     * centre's bags under two prefixes and the labels on the shelf disagreeing
     * with the stock record (project owner, 2026-11-13). Setting one where
     * there was none is always allowed. The requests refuse the change with a
     * field message first; this holds it on every other path.
     */
    protected static function booted(): void
    {
        static::saving(function (Facility $facility): void {
            if (! $facility->isDirty('abbreviation')) {
                return;
            }

            $abbreviation = strtoupper(trim((string) $facility->abbreviation));
            $facility->abbreviation = $abbreviation === '' ? null : $abbreviation;

            if ($facility->exists
                && $facility->getOriginal('abbreviation') !== null
                && $facility->abbreviation !== $facility->getOriginal('abbreviation')
                && $facility->hasBookedBags()) {
                throw new LogicException('A blood center\'s abbreviation cannot change once it has booked a bag.');
            }
        });
    }

    /**
     * Whether a bag from one of this centre's donations is in stock or ever was.
     */
    public function hasBookedBags(): bool
    {
        return $this->exists && BloodUnit::query()
            ->whereIn('donation_id', Donation::query()->select('id')->where('facility_id', $this->getKey()))
            ->exists();
    }

    /**
     * Whether the abbreviation can no longer change: it is on a booked bag.
     */
    public function abbreviationLocked(): bool
    {
        return $this->getOriginal('abbreviation') !== null && $this->hasBookedBags();
    }

    /**
     * Whether the centre takes bookings on a day: one of its open weekdays
     * (every day when none are set), and not one of its closed dates.
     */
    public function isOpenOn(\DateTimeInterface $day): bool
    {
        return $this->closedReasonOn($day) === null;
    }

    /**
     * Why the centre takes no bookings on a day, or null when it does.
     */
    public function closedReasonOn(\DateTimeInterface $day): ?string
    {
        $date = $day->format('Y-m-d');
        foreach ($this->closed_dates ?? [] as $closed) {
            if (($closed['date'] ?? null) === $date) {
                return filled($closed['reason'] ?? null) ? (string) $closed['reason'] : 'Closed';
            }
        }

        $openDays = $this->open_days;
        if (is_array($openDays) && $openDays !== [] && ! in_array((int) $day->format('N') - 1, array_map('intval', $openDays), true)) {
            return 'Not open on '.$day->format('l').'s';
        }

        return null;
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
     * Every user account attached to this facility.
     */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The blood stock held at this facility.
     */
    public function bloodUnits(): HasMany
    {
        return $this->hasMany(BloodUnit::class);
    }

    /**
     * Requests this facility raised against other facilities.
     *
     * Named for the direction it travels. The unqualified "blood requests of a
     * facility" is ambiguous — every facility has two sets — so neither set is
     * given the plain name.
     */
    public function outgoingBloodRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class, 'facility_id');
    }

    /**
     * Requests other facilities addressed to this one. The incoming queue.
     */
    public function incomingBloodRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class, 'target_facility_id');
    }

    /**
     * The administrator who approved this facility, if any.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The account that speaks for this facility.
     *
     * Created alongside the facility by a Super Admin and given the Admin
     * level, so it is the one account guaranteed to be able to staff the
     * portal. The column keeps its historical name from when facilities
     * self-registered; the meaning is unchanged.
     */
    public function primaryAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registration_contact_user_id');
    }

    /**
     * Limit the query to facilities currently open to donor bookings.
     */
    public function scopeAcceptingDonations(Builder $query): Builder
    {
        return $query->where('is_accepting_donations', true);
    }

    /**
     * Limit the query to facilities cleared to act on real data.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', FacilityStatus::Approved);
    }
}
