<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\TransactionCategory;
use App\Enums\TransactionChannel;
use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBillingTransactionsRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The facility is deliberately absent: it comes from the authenticated
     * user, never from input, so no centre can read another's journal.
     * Dates are operational (Manila) days.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['sometimes', Rule::in(TransactionCategory::values())],
            'type' => ['sometimes', Rule::in(TransactionType::values())],
            'channel' => ['sometimes', Rule::in(TransactionChannel::values())],
            'payment_method' => ['sometimes', Rule::in(PaymentMethod::values())],
            'cash_session_id' => ['sometimes', 'integer'],
            'recorded_by' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:60'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
