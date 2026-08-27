<?php

namespace App\Models;

use App\Enums\FacilityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Facility extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The approval trail is deliberately absent from this list.
     *
     * status, approved_at, approved_by, rejection_reason, resubmitted_at and
     * registration_contact_user_id decide whether an organisation may touch
     * real blood stock, so no mass-assignment path may reach them. The approval
     * and registration services set them by direct assignment.
     */
    protected $fillable = [
        'facility_type_id',
        'name',
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
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        // Fail closed. The column default is 'approved' so the facilities that
        // already existed before approval was introduced stay usable, but any
        // facility this application constructs starts unapproved unless a
        // service says otherwise.
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
     * Every user account attached to this facility.
     */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The administrator who approved this facility, if any.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The one user permitted to resubmit a rejected registration.
     */
    public function registrationContact(): BelongsTo
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
