<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single questionnaire answer. These records carry infectious disease status,
 * surgery and transfusion history, and medication use, so the answer is
 * encrypted at rest and hidden from array and JSON serialisation.
 */
class EligibilityScreeningAnswer extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'screening_id',
        'question_code',
        'answer',
    ];

    protected $hidden = [
        'answer',
    ];

    /**
     * `encrypted:json` rather than a boolean cast: Laravel only supports
     * encryption for the string, array, collection, json and object casts, and
     * a plain boolean cast would silently store the value in clear text.
     */
    protected function casts(): array
    {
        return [
            'answer' => 'encrypted:json',
        ];
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(EligibilityScreening::class, 'screening_id');
    }
}
