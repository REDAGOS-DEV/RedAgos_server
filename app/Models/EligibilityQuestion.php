<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EligibilityQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'section_key',
        'code',
        'number',
        'text',
        'disqualify_if_answer',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'number' => 'integer',
            'disqualify_if_answer' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Limit the query to the questions that make up a given questionnaire version.
     */
    public function scopeForVersion(Builder $query, int $version): Builder
    {
        return $query->where('version', $version)
            ->where('is_active', true)
            ->orderBy('number');
    }
}
