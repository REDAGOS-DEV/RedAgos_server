<?php

namespace App\Http\Requests;

use Illuminate\Support\Arr;

/**
 * One step of the donor registration page, checked before Continue moves on
 * (project owner, 2026-11-11): "this email address is already registered" on
 * step 1, not after the whole form.
 *
 * The step's own fields only, under RegisterDonorRequest's rules and messages,
 * so a step that passes here passes there. Nothing is stored; the account is
 * still made by the one POST /donors/register at the end.
 */
class CheckDonorRegistrationStepRequest extends RegisterDonorRequest
{
    /**
     * The fields on each step of the page (useDonorRegistration.ts).
     *
     * @var array<int, list<string>>
     */
    public const STEP_FIELDS = [
        1 => ['email', 'password'],
        2 => ['first_name', 'last_name', 'birth_date', 'gender', 'blood_type'],
        3 => ['phone', 'address', 'valid_id_type', 'valid_id_number', 'terms_accepted'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = (int) $this->input('step');

        return [
            'step' => ['required', 'integer', 'in:'.implode(',', array_keys(self::STEP_FIELDS))],
            ...Arr::only(parent::rules(), self::STEP_FIELDS[$step] ?? []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'step.required' => 'Which step is being checked?',
            'step.in' => 'There is no such registration step.',
        ];
    }
}
