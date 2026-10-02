<?php

namespace App\Models;

use App\Enums\MarkerResult;
use App\Enums\SerologyMarker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The Testing department's five-marker infection panel for a donation.
 *
 * RedAgos does not run the assays. A medical technologist does, and each
 * column is the final reading they reported. One row per donation.
 *
 * Which marker was reactive is the most sensitive fact RedAgos holds. It is
 * shown to the Testing department and on its referral list, and nowhere else:
 * not in rejection reasons, not in audit context, not to the donor.
 *
 * A reactive panel is immutable. It has already rejected the donation,
 * deferred the donor and referred them, so the model refuses to change or
 * delete it whoever asks — the service guards are the first line, this is the
 * last.
 */
class DonationSerology extends Model
{
    protected $table = 'donation_serology';

    protected $fillable = [
        'donation_id',
        'hiv',
        'hbsag',
        'hcv',
        'syphilis',
        'malaria',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'hiv' => MarkerResult::class,
            'hbsag' => MarkerResult::class,
            'hcv' => MarkerResult::class,
            'syphilis' => MarkerResult::class,
            'malaria' => MarkerResult::class,
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DonationSerology $serology): void {
            if ($serology->wasReactive()) {
                throw new LogicException('A reactive serology result cannot be changed.');
            }
        });

        static::deleting(function (DonationSerology $serology): void {
            if ($serology->wasReactive()) {
                throw new LogicException('A reactive serology result cannot be deleted.');
            }
        });
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * Whether the panel as stored — before any pending change — was reactive.
     */
    public function wasReactive(): bool
    {
        foreach (SerologyMarker::cases() as $marker) {
            $original = $this->getOriginal($marker->value);
            $value = $original instanceof MarkerResult ? $original : MarkerResult::tryFrom((string) $original);

            if ($value?->isReactive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The form's "Screened by": the staff member who saved this section.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The reading recorded for one marker.
     */
    public function resultFor(SerologyMarker $marker): ?MarkerResult
    {
        return $this->getAttribute($marker->value);
    }

    /**
     * The markers that came back reactive, in the form's order.
     *
     * @return array<int, SerologyMarker>
     */
    public function reactiveMarkers(): array
    {
        return array_values(array_filter(
            SerologyMarker::cases(),
            fn (SerologyMarker $marker): bool => $this->resultFor($marker)?->isReactive() ?? false
        ));
    }

    /**
     * Determine whether any marker came back reactive.
     */
    public function isReactive(): bool
    {
        return $this->reactiveMarkers() !== [];
    }
}
