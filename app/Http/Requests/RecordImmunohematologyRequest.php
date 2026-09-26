<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blood_type_id.required' => 'Record the ABO group and Rh type.',
        ];
    }
}
