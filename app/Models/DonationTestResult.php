<?php

namespace App\Models;

use App\Enums\TestResult;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The screening outcome recorded against a donation.
 *
 * RedAgos does not perform the assay. A qualified professional does, and this
 * is the record of what they reported — see the scope boundary in
 * docs/BLOOD-CENTER.md.
 */
class DonationTestResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'donation_id',
        'recorded_by',
        'blood_type_id',
        'result',
        'tested_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'result' => TestResult::class,
            'tested_at' => 'datetime',
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
     * The staff member who entered the result.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
