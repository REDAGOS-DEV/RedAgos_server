<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Enums\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'department' => ['sometimes', 'string', Rule::in(Department::values())],
            'account_status' => ['sometimes', 'string', Rule::enum(AccountStatus::class)],
            'supervisors_only' => ['sometimes', 'boolean'],
            'include_deleted' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
