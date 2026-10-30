<?php

namespace App\Http\Requests;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestPurpose;
use App\Enums\RequestSource;
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
            'request_source' => ['sometimes', Rule::in(RequestSource::values())],
            'request_purpose' => ['sometimes', Rule::in(RequestPurpose::values())],
            'blood_type_id' => ['sometimes', 'integer', 'exists:blood_types,id'],
            'component_id' => ['sometimes', 'integer', 'exists:blood_components,id'],
            // A reference, a patient's name or the other facility's name.
            'search' => ['sometimes', 'string', 'max:100'],
            // When it was asked for, as operational dates.
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'awaiting_release' => ['sometimes', 'boolean'],
            'awaiting_receipt' => ['sometimes', 'boolean'],
            // 1: the invoice clears release; 0: no invoice yet, or money still owed.
            'release_cleared' => ['sometimes', 'boolean'],
            'open' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
