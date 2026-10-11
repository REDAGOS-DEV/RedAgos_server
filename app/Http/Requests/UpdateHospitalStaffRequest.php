<?php

namespace App\Http\Requests;

use App\Enums\HospitalRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A blood bank Head changing a colleague's role or title.
 *
 * Whether the change would leave the blood bank without a Head is decided by
 * HospitalStaffService, which can see the rest of the roster.
 */
class UpdateHospitalStaffRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'hospital_role' => ['sometimes', 'required', 'string', Rule::in(HospitalRole::values())],
            'position' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
