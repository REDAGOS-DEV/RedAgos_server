<?php

namespace App\Http\Requests;

use App\Enums\AboGroup;
use App\Enums\AntibodyScreen;
use App\Models\BloodType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordImmunohematologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `recorded_by` and `recorded_at` are absent by design: the form's
     * "Screened by" is the authenticated staff member, stamped when they save.
     *
     * ABO and Rh arrive as one `blood_type_id`. The Testing page shows two
     * pickers and resolves them to the combined `blood_types` row every other
     * part of the system uses.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'blood_type_id' => ['required', 'integer', 'exists:blood_types,id'],

            // The forward ABO read. The reverse group and the antibody screen
            // are no longer asked for on the Testing page (project owner,
            // 2026-10-22); when they are sent, the clearance still turns on
            // them. See DonationImmunohematology.
            'forward_group' => ['required', 'string', Rule::in(AboGroup::values())],
            'reverse_group' => ['sometimes', 'nullable', 'string', Rule::in(AboGroup::values())],
            'antibody_screen' => ['sometimes', 'nullable', 'string', Rule::in(AntibodyScreen::values())],

            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The typing recorded must be the forward group read at the bench.
     *
     * blood_type_id is what the rest of the system uses, so it cannot be
     * allowed to say something the forward grouping did not. The reverse group
     * is free to disagree: that disagreement is itself the finding, and it
     * holds the clearance rather than being refused.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $typed = AboGroup::ofBloodTypeCode(BloodType::query()->whereKey($this->integer('blood_type_id'))->value('code'));

                if ($typed?->value !== $this->input('forward_group')) {
                    $validator->errors()->add('forward_group', 'The ABO group must match the forward grouping.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blood_type_id.required' => 'Record the ABO group and Rh type.',
            'forward_group.required' => 'Record the forward grouping.',
        ];
    }
}
