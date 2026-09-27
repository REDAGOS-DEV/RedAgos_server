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
 * record of what they read: forward and reverse grouping, the Rh type (carried
 * in the combined blood_type_id) and the antibody screen. One row per
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
     * Forward and reverse grouping are two independent reads of the same ABO
     * group; a disagreement means one of them is wrong, and nobody should pick
     * which. A positive antibody screen needs the antibody identified first.
     */
    public function clearanceHold(): ?string
    {
        return match (true) {
            $this->forward_group === null || $this->reverse_group === null || $this->antibody_screen === null => 'grouping_incomplete',
            $this->forward_group !== $this->reverse_group => 'abo_discrepancy',
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
