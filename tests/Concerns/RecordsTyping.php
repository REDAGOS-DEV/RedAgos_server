<?php

namespace Tests\Concerns;

use App\Enums\AboGroup;
use App\Models\BloodType;

/**
 * Build an immunohematology payload the way the bench reads it.
 */
trait RecordsTyping
{
    /**
     * A concordant typing of the given blood type: forward and reverse agree
     * and the antibody screen is negative, so it earns its clearance.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function concordantTyping(BloodType|int $bloodType, array $overrides = []): array
    {
        $bloodType = $bloodType instanceof BloodType ? $bloodType : BloodType::findOrFail($bloodType);
        $group = AboGroup::ofBloodTypeCode($bloodType->code)?->value;

        return [
            'blood_type_id' => $bloodType->id,
            'forward_group' => $group,
            'reverse_group' => $group,
            'antibody_screen' => 'negative',
            ...$overrides,
        ];
    }
}
