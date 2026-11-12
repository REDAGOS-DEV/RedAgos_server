<?php

namespace App\Models;

use App\Enums\PreRegistrationSource;
use App\Enums\PreRegistrationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on a drive's list, put there through its registration link.
 *
 * A planning record: it proves nothing about eligibility, attendance or
 * donation. See the create_drive_pre_registrations migration.
 */
class DrivePreRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'mobile_event_id',
        'source',
        'status',
        'partner_submission_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'birth_date',
        'affiliation',
        'consent_at',
        'donor_id',
        'matched_by',
        'matched_at',
        'appointment_id',
        'withdrawn_by',
        'withdrawn_at',
        'anonymised_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pre_registered',
    ];

    protected function casts(): array
    {
        return [
            'source' => PreRegistrationSource::class,
            'status' => PreRegistrationStatus::class,
            'birth_date' => 'date',
            'consent_at' => 'datetime',
            'matched_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'anonymised_at' => 'datetime',
        ];
    }

    public function drive(): BelongsTo
    {
        return $this->belongsTo(MobileEvent::class, 'mobile_event_id');
    }

    public function partnerSubmission(): BelongsTo
    {
        return $this->belongsTo(DrivePartnerSubmission::class, 'partner_submission_id');
    }

    /**
     * The existing donor staff confirmed this person is.
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(DonationAppointment::class, 'appointment_id');
    }

    /**
     * Limit the query to people still on the list.
     */
    public function scopeOnList(Builder $query): Builder
    {
        return $query->where('status', '!=', PreRegistrationStatus::Withdrawn->value);
    }
}
