<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The days a hospital blood bank sends its weekly request to one blood centre.
 *
 * Kept by the hospital itself. A weekly request may only be sent on one of
 * these days (WeeklyRequestService), which is the whole of the rule: the
 * schedule is not a promise the centre made, and nothing on the centre's side
 * reads it.
 */
class ReplenishmentSchedule extends Model
{
    use HasFactory;

    /**
     * Short weekday names by ISO weekday, as the schedule is read back to staff.
     *
     * @var array<int, string>
     */
    public const WEEKDAY_LABELS = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    protected $fillable = [
        'facility_id',
        'target_facility_id',
        'days_of_week',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'days_of_week' => 'array',
        ];
    }

    /**
     * The hospital blood bank that keeps the schedule.
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * The blood centre the weekly request is sent to.
     */
    public function targetFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'target_facility_id');
    }

    /**
     * The staff member who last set the days.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The request days as ISO weekdays, Monday first.
     *
     * @return array<int, int>
     */
    public function weekdays(): array
    {
        $days = array_values(array_unique(array_map('intval', $this->days_of_week ?? [])));
        sort($days);

        return $days;
    }

    /**
     * Determine whether the given ISO weekday (1 = Monday … 7 = Sunday) is a request day.
     */
    public function includesWeekday(int $isoWeekday): bool
    {
        return in_array($isoWeekday, $this->weekdays(), true);
    }

    /**
     * Name the request days for a message: "Mon, Wed, Fri".
     */
    public function weekdaysLabel(): string
    {
        return implode(', ', array_map(fn (int $day): string => self::WEEKDAY_LABELS[$day] ?? (string) $day, $this->weekdays()));
    }

    /**
     * Limit the query to one hospital's schedules.
     */
    public function scopeForFacility(Builder $query, int $facilityId): Builder
    {
        return $query->where('facility_id', $facilityId);
    }
}
