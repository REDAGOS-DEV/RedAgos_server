<?php

namespace App\Models;

use App\Enums\QuestionKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EligibilityQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'section_key',
        'section_title',
        'section_number',
        'code',
        'number',
        'text',
        'disqualify_if_answer',
        'applies_to_gender',
        'kind',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'number' => 'integer',
            'section_number' => 'integer',
            'disqualify_if_answer' => 'boolean',
            'kind' => QuestionKind::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Limit the query to the questions that make up a given questionnaire version.
     *
     * Ordered by section first. Before v2 the sections fell out in the right
     * order only because each one's question numbers happened to be contiguous,
     * which is an accident of there having been two sections rather than a
     * guarantee. section_number says it outright.
     */
    public function scopeForVersion(Builder $query, int $version): Builder
    {
        return $query->where('version', $version)
            ->where('is_active', true)
            ->orderBy('section_number')
            ->orderBy('number')
            ->orderBy('code');
    }

    /**
     * Determine whether this question is asked of a donor of a given gender.
     *
     * The DOH form's question 5 is for female donors. A donor whose gender is
     * unrecorded, 'other' or 'prefer_not_to_say' is still offered it rather
     * than silently skipped -- a physiological safety question is not something
     * to drop over a privacy choice -- but is not required to answer.
     */
    public function appliesToGender(?string $gender): bool
    {
        if ($this->applies_to_gender === null) {
            return true;
        }

        return $gender !== 'male';
    }

    /**
     * Determine whether a donor of a given gender must answer this question.
     */
    public function isRequiredForGender(?string $gender): bool
    {
        if ($this->applies_to_gender === null) {
            return true;
        }

        return $gender === $this->applies_to_gender;
    }
}
