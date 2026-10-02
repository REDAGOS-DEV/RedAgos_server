<?php

namespace App\Enums;

use App\Http\Requests\DeclareComponentsRequest;
use App\Http\Requests\RecordCollectionRequest;
use App\Http\Requests\RecordImmunohematologyRequest;
use App\Http\Requests\RecordScreeningRequest;
use App\Http\Requests\RecordSerologyRequest;

/**
 * A saved record that may only be changed through an approved correction request.
 *
 * Once one of these exists its writer cannot simply save over it: they ask, and
 * someone else in the department decides. See "Correction requests" in
 * docs/IMPLEMENTATION_DECISIONS.md.
 */
enum CorrectionSubject: string
{
    case Screening = 'screening';

    case Collection = 'collection';

    case Immunohematology = 'immunohematology';

    case Serology = 'serology';

    case Components = 'components';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Screening => 'Screening',
            self::Collection => 'Collection record',
            self::Immunohematology => 'Blood typing',
            self::Serology => 'Serology panel',
            self::Components => 'Component breakdown',
        };
    }

    /**
     * The ability that writes this record, which a requester must hold.
     */
    public function writeAbility(): string
    {
        return match ($this) {
            self::Screening => 'donations.screen',
            self::Collection => 'donations.collect',
            self::Immunohematology => 'lab.record_immunohematology',
            self::Serology => 'lab.record_serology',
            self::Components => 'lab.record_components',
        };
    }

    /**
     * The department the record belongs to, whose approver decides.
     */
    public function department(): Department
    {
        return match ($this) {
            self::Screening, self::Collection => Department::Collection,
            self::Immunohematology, self::Serology => Department::Testing,
            self::Components => Department::Processing,
        };
    }

    /**
     * The form request whose rules the corrected values must pass — the same
     * ones the original write passed.
     *
     * @return class-string
     */
    public function requestClass(): string
    {
        return match ($this) {
            self::Screening => RecordScreeningRequest::class,
            self::Collection => RecordCollectionRequest::class,
            self::Immunohematology => RecordImmunohematologyRequest::class,
            self::Serology => RecordSerologyRequest::class,
            self::Components => DeclareComponentsRequest::class,
        };
    }
}
