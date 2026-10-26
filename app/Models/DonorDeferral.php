<?php

namespace App\Models;

use App\Enums\ScreeningOutcome;
use App\Support\OperationalDay;
use Database\Factories\DonorDeferralFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deferral staff recorded on a donor by hand, outside any visit's screening.
 *
 * Read with the screening and laboratory deferrals through
 * DonorDeferralRepository, so every screen that asks whether a donor is
 * deferred hears about it.
 */
class DonorDeferral extends Model
{
    /** @use HasFactory<DonorDeferralFactory> */
    use HasFactory;

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const LIFTED = 'lifted';

    protected $fillable = [
        'donor_id',
        'facility_id',
        'outcome',
        // The centre's deferral code; `reason` is what it stood for when this
        // was recorded. No code on rows recorded before codes existed.
        'reason_code',
        'reason',
        'deferred_on',
        'ends_on',
        'recorded_by',
        'lifted_at',
        'lifted_by',
        'lift_reason',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => ScreeningOutcome::class,
            'deferred_on' => 'date',
            'ends_on' => 'date',
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * Limit to deferrals still in force today: not lifted and not past their end.
     */
    public function scopeInForce(Builder $query): void
    {
        $query->whereNull('lifted_at')
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', OperationalDay::todayAsDate()));
    }

    /**
     * Limit to a temporary deferral whose last day has passed, never lifted.
     */
    public function scopeExpired(Builder $query): void
    {
        $query->whereNull('lifted_at')
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<', OperationalDay::todayAsDate());
    }

    /**
     * active, expired (a temporary deferral past its last day) or lifted.
     */
    public function status(): string
    {
        return match (true) {
            $this->lifted_at !== null => self::LIFTED,
            $this->ends_on !== null && $this->ends_on->toDateString() < OperationalDay::todayAsDate() => self::EXPIRED,
            default => self::ACTIVE,
        };
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function lifter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }
}
