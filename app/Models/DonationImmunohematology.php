<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Testing department's confirmatory ABO and Rh typing of a donation.
 *
 * RedAgos does not type blood. A medical technologist does, and this is the
 * record of what they read. One row per donation; a correction edits it.
 */
class DonationImmunohematology extends Model
{
    protected $table = 'donation_immunohematology';

    protected $fillable = [
        'donation_id',
        'blood_type_id',
        'notes',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function bloodType(): BelongsTo
    {
        return $this->belongsTo(BloodType::class);
    }

    /**
     * The form's "Screened by": the staff member who saved this section.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
