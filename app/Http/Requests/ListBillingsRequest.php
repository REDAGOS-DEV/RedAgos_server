<?php

namespace App\Http\Requests;

use App\Enums\BillingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBillingsRequest extends FormRequest
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
     * The facility is deliberately absent: it comes from the authenticated
     * user, never from input, so no centre can list another's statements.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(BillingStatus::values())],
            'outstanding' => ['sometimes', 'boolean'],
            // Patient bills, settled at the counter, or the hospitals' weekly statements.
            'category' => ['sometimes', Rule::in(['patient', 'weekly'])],
            'search' => ['sometimes', 'string', 'max:60'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
