<?php

namespace App\Models;

use App\Enums\IdentityStatus;
use App\Enums\ValidIdType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DonorProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'donor_id';

    public $incrementing = false;

    protected $fillable = [
        'donor_id',
        'blood_type_id',
        'gender',
        'birth_date',
        'address',
        'last_donation_date',
        'valid_id_type',
        'valid_id_number',
        'valid_id_image_path',
        'identity_status',
        'identity_submitted_at',
        'identity_submission_version',
        'identity_reviewed_at',
        'identity_reviewed_by',
        'identity_rejection_reason',
        'profile_image_path',
        'notification_preferences',
    ];

    /**
     * The stored path to the identity document.
     *
     * Hidden so an accidental model serialisation cannot hand a client the
     * location of a government ID. The document is only ever reachable through
     * the authenticated, audited route that streams it.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'valid_id_image_path',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'last_donation_date' => 'date',
            'valid_id_type' => ValidIdType::class,
            'identity_status' => IdentityStatus::class,
            'identity_submitted_at' => 'datetime',
            'identity_reviewed_at' => 'datetime',
            'identity_submission_version' => 'integer',
            'notification_preferences' => 'array',
        ];
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }

    public function bloodType(): BelongsTo
    {
        return $this->belongsTo(BloodType::class);
    }

    /**
     * The administrator who last decided on this donor's identity document.
     */
    public function identityReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'identity_reviewed_by');
    }

    /**
     * Every appointment this donor has booked, at any facility.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(DonationAppointment::class, 'donor_id', 'donor_id');
    }

    /**
     * Every donation recorded for this donor, at any facility.
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'donor_id', 'donor_id');
    }
}
