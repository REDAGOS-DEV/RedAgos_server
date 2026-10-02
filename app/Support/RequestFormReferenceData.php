<?php

namespace App\Support;

use App\Enums\IndicationCode;
use App\Enums\RequestPurpose;
use App\Enums\UrgencyLevel;
use App\Models\BloodComponent;
use App\Models\BloodType;

/**
 * Everything a blood request form needs to be filled in.
 *
 * Served to the Blood Bank Portal's request form and to the blood centre's
 * walk-in form from this one place. The indication codes are projected from the
 * IndicationCode enum rather than duplicated in the client: they are the
 * criteria a physician certifies against, and two copies of a clinical list are
 * two chances to disagree — a dropdown offering a code the API would reject, or
 * worse, the wrong criterion text beside the right code.
 */
final class RequestFormReferenceData
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        return [
            'blood_types' => BloodType::query()->orderBy('code')->get()
                ->map(fn (BloodType $type): array => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'label' => $type->label,
                ])->all(),

            'components' => BloodComponent::query()->orderBy('name')->get()
                ->map(fn (BloodComponent $component): array => [
                    'id' => $component->id,
                    'name' => $component->name,
                    'indication_codes' => array_map(
                        fn (IndicationCode $code): array => [
                            'code' => $code->value,
                            'label' => $code->label(),
                            'description' => $code->description(),
                            // The client uses this to reveal the "please
                            // specify" box, so the rule for when an
                            // explanation is required lives in one place.
                            'requires_explanation' => $code->triggersReview(),
                        ],
                        IndicationCode::forComponentName((string) $component->name)
                    ),
                ])->all(),

            'purposes' => array_map(
                fn (RequestPurpose $purpose): array => [
                    'value' => $purpose->value,
                    'label' => $purpose->label(),
                    'requires_patient' => $purpose->requiresPatient(),
                ],
                RequestPurpose::cases()
            ),

            // Labelled for the form, which prints ROUTINE and STAT. The stored
            // value stays `emergency`, which the rest of the workflow keys off.
            'priorities' => array_map(
                fn (UrgencyLevel $level): array => [
                    'value' => $level->value,
                    'label' => $level->isPrioritised() ? 'STAT' : 'Routine',
                ],
                UrgencyLevel::cases()
            ),
        ];
    }
}
