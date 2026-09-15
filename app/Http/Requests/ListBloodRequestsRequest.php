<?php

namespace App\Http\Requests;

use App\Enums\BloodRequestStatus;
use App\Enums\UrgencyLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBloodRequestsRequest extends FormRequest
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
     * Shared by both sides of the exchange — the requester's own list and the
     * fulfiller's incoming queue filter on the same fields. Which side is being
     * listed is decided by the route, never by a parameter.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(BloodRequestStatus::values())],
            'urgency_level' => ['sometimes', Rule::in(UrgencyLevel::values())],
            'blood_type_id' => ['sometimes', 'integer', 'exists:blood_types,id'],
            'component_id' => ['sometimes', 'integer', 'exists:blood_components,id'],
            'search' => ['sometimes', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
