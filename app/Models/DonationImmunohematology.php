<?php

namespace App\Models;

use App\Enums\AboGroup;
use App\Enums\AntibodyScreen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immunohematology's confirmatory ABO and Rh typing of a donation.
 *
 * RedAgos does not type blood. A medical technologist does, and this is the
 * record of what they read: the forward grouping and the Rh type (carried in
 * the combined blood_type_id), and, where recorded, the reverse grouping and
 * the antibody screen. One row per
 * donation; a correction edits it, until the clearance token is issued.
 */
class DonationImmunohematology extends Model
{
    protected $table = 'donation_immunohematology';

    protected $fillable = [
        'donation_id',
        'blood_type_id',
        'forward_group',
        'reverse_group',
        'antibody_screen',
        'notes',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'forward_group' => AboGroup::class,
            'reverse_group' => AboGroup::class,
            'antibody_screen' => AntibodyScreen::class,
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * Why this typing cannot be cleared yet, or null when it can.
     *
     * The Testing page records the forward ABO read and the Rh type only
     * (project owner, 2026-10-22), and that typing clears. A reverse group or
     * antibody screen is optional, but one that was recorded still counts:
     * forward and reverse are two independent reads of the same ABO group, so
     * a disagreement means one of them is wrong, and nobody should pick which;
     * a positive antibody screen needs the antibody identified first.
     */
    public function clearanceHold(): ?string
    {
        return match (true) {
            $this->forward_group === null => 'grouping_incomplete',
            $this->reverse_group !== null && $this->forward_group !== $this->reverse_group => 'abo_discrepancy',
            $this->antibody_screen === AntibodyScreen::Positive => 'antibody_screen_positive',
            default => null,
        };
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
