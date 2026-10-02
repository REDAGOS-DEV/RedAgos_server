<?php

namespace App\Models;

use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One patient's hold on one hospital bag: Tag Assigned, Tag Crossmatched, and how it ended.
 *
 * A tag is the record that a specific bag was promised to a specific patient,
 * so it is never deleted and its patient is never rewritten. It moves forward
 * through its own lifecycle — crossmatch, transfusion, or untagging — and,
 * after an Untagged Crossmatched ending, may be stamped once more when staff
 * confirm the bag is back in storage. The model refuses anything else.
 *
 * The scheduler untags with a bulk update and so bypasses these guards. That
 * is deliberate and narrow: the sweep writes only the untagging columns, under
 * the same predicates it re-asserts under lock.
 */
class UnitTag extends Model
{
    use HasFactory;
    use HasPatient;

    /**
     * The columns fixed the moment a tag is written.
     *
     * @var array<int, string>
     */
    private const FROZEN = [
        'hospital_unit_id',
        'facility_id',
        'transfusion_request_id',
        'patient_surname',
        'patient_first_name',
        'patient_middle_name',
        'patient_age',
        'patient_sex',
        'patient_record_number',
        'patient_ward',
        'attending_physician',
        'patient_blood_type_id',
        'tagged_at',
        'tagged_by',
        'crossmatch_deadline_at',
    ];

    /**
     * The only columns confirming a return may write on an ended tag.
     *
     * @var array<int, string>
     */
    private const RETURN = ['returned_at', 'returned_by', 'updated_at'];

    protected $fillable = [
        'hospital_unit_id',
        'facility_id',
        'transfusion_request_id',
        'patient_surname',
        'patient_first_name',
        'patient_middle_name',
        'patient_age',
        'patient_sex',
        'patient_record_number',
        'patient_ward',
        'attending_physician',
        'patient_blood_type_id',
        'status',
        'tagged_at',
        'tagged_by',
        'crossmatch_deadline_at',
    ];

    /**
     * Mirrors the column default; Eloquent does not read it back on insert.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'tag_assigned',
    ];

    protected function casts(): array
    {
        return [
            'status' => UnitTagStatus::class,
            'untag_reason' => UntagReason::class,
            'patient_age' => 'integer',
            'tagged_at' => 'immutable_datetime',
            'crossmatch_deadline_at' => 'immutable_datetime',
            'crossmatched_at' => 'immutable_datetime',
            'transfusion_deadline_at' => 'immutable_datetime',
            'transfused_at' => 'immutable_datetime',
            'untagged_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (UnitTag $tag): void {
            $dirty = array_keys($tag->getDirty());

            if (array_intersect($dirty, self::FROZEN) !== []) {
                throw new LogicException('A tag\'s patient, bag and tagging moment cannot be changed once written.');
            }

            $original = UnitTagStatus::from($tag->getRawOriginal('status'));

            if ($original->claimsUnit()) {
                if ($tag->isDirty('status') && ! $original->canTransitionTo($tag->status)) {
                    throw new LogicException("A tag cannot move from {$original->value} to {$tag->status->value}.");
                }

                return;
            }

            $returning = $original === UnitTagStatus::UntaggedCrossmatched
                && $tag->getRawOriginal('returned_at') === null
                && array_diff($dirty, self::RETURN) === [];

            if (! $returning) {
                throw new LogicException('A tag that has ended cannot be changed.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A tag is part of the bag\'s history and cannot be deleted.');
        });
    }

    /**
     * The bag this tag holds.
     */
    public function hospitalUnit(): BelongsTo
    {
        return $this->belongsTo(HospitalUnit::class);
    }

    /**
     * The hospital blood bank that placed the tag.
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * The patient's transfusion requirement, when the tag was linked to one.
     */
    public function transfusionRequest(): BelongsTo
    {
        return $this->belongsTo(TransfusionRequest::class);
    }

    /**
     * The patient's blood type, when staff recorded it.
     */
    public function patientBloodType(): BelongsTo
    {
        return $this->belongsTo(BloodType::class, 'patient_blood_type_id');
    }

    public function taggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tagged_by');
    }

    public function crossmatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'crossmatched_by');
    }

    public function transfusedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transfused_by');
    }

    /**
     * The staff member who released the tag; null when the scheduler ended it.
     */
    public function untaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'untagged_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * Limit the query to tags that still hold their bag for a patient.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('unit_tags.status', UnitTagStatus::claimingValues());
    }

    /**
     * Get the deadline the tag is currently running against, if it is active.
     */
    public function activeDeadline(): ?CarbonImmutable
    {
        return match ($this->status) {
            UnitTagStatus::TagAssigned => $this->crossmatch_deadline_at,
            UnitTagStatus::TagCrossmatched => $this->transfusion_deadline_at,
            default => null,
        };
    }
}
