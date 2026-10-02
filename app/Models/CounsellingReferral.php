<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A donor the Testing department must follow up after a reactive serology result.
 *
 * Opened automatically when serology comes back reactive, in the same
 * transaction that rejects the donation. Its existence is also the donor's
 * permanent deferral — see the migration that created the table.
 */
class CounsellingReferral extends Model
{
    protected $fillable = [
        'donation_id',
        'donor_id',
        'facility_id',
        'status',
        'contacted_at',
        'referred_at',
        'closed_at',
        'note',
        'updated_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'contacted_at' => 'datetime',
            'referred_at' => 'datetime',
            'closed_at' => 'datetime',
            // Free text about a person's infection follow-up: encrypted at
            // rest, as the questionnaire answers are.
            'note' => 'encrypted',
        ];
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function donorProfile(): BelongsTo
    {
        return $this->belongsTo(DonorProfile::class, 'donor_id', 'donor_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * The staff member who last moved the referral on.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
