<?php

namespace App\Models;

use Database\Factories\DeferralCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a blood centre's deferral codes: what a deferral record shows, and
 * the reason only staff allowed the clinical record may read.
 *
 * Managed through DeferralCodeService.
 */
class DeferralCode extends Model
{
    /** @use HasFactory<DeferralCodeFactory> */
    use HasFactory;

    /**
     * A centre's first list: the reasons the Deferrals page offered before
     * codes existed. Haemoglobin is R2, in two: low and high (project owner,
     * 2026-10-24).
     *
     * @var array<int, array{code: string, reason: string}>
     */
    public const DEFAULTS = [
        ['code' => 'R1', 'reason' => 'Recent tattoo/piercing'],
        ['code' => 'R2-Low', 'reason' => 'Low haemoglobin'],
        ['code' => 'R2-High', 'reason' => 'High haemoglobin'],
        ['code' => 'R3', 'reason' => 'Recent illness or fever'],
        ['code' => 'R4', 'reason' => 'Recent surgery or dental work'],
        ['code' => 'R5', 'reason' => 'On medication'],
        ['code' => 'R6', 'reason' => 'Pregnancy or breastfeeding'],
        ['code' => 'R7', 'reason' => 'Blood pressure out of range'],
        ['code' => 'R8', 'reason' => 'Underweight'],
        ['code' => 'R9', 'reason' => 'Recent vaccination'],
        ['code' => 'R10', 'reason' => 'Recent travel to a malaria area'],
        ['code' => 'R11', 'reason' => 'Chronic condition'],
        ['code' => 'R12', 'reason' => 'High-risk behaviour'],
    ];

    /**
     * What a deferral recorded under "Other" reads as where a code would: the
     * reason was typed, and no code stands for it.
     */
    public const OTHER = 'Other';

    protected $fillable = [
        'facility_id',
        'code',
        'reason',
        'retired_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'retired_at' => 'datetime',
        ];
    }

    /**
     * Limit to codes still offered when recording a deferral.
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('retired_at');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
