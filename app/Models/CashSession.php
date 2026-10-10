<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A cashier's shift at the billing counter: the drawer from its float to its count.
 *
 * Opened with the cash put in the drawer, it takes the counter's payments, and
 * is closed with the drawer counted. What the drawer should hold is worked out
 * at close from the shift's own transactions and frozen here beside the count.
 *
 * One open shift per cashier, and a closed shift never changes — held by the
 * database (create_cash_sessions_table) and refused by the hooks below first.
 */
class CashSession extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'session_number',
        'facility_id',
        'cashier_id',
        'counter_label',
        'status',
        'opening_float',
        'opened_at',
        'expected_cash',
        'counted_cash',
        'variance',
        'count_breakdown',
        'closing_note',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'count_breakdown' => 'array',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CashSession $session): void {
            if ($session->getOriginal('status') === self::STATUS_CLOSED) {
                throw new LogicException('A closed cash shift cannot be changed.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A cash shift cannot be deleted.');
        });
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * The journal rows posted during this shift.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BillingTransaction::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
