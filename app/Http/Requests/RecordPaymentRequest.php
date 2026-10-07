<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    /**
     * Set by CorrectionService when this request validates a correction to a
     * payment that already exists, so its own reference number is not a
     * duplicate of itself. Null on an ordinary payment.
     */
    public ?int $correctingPaymentId = null;

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
     * A GCash settlement must carry its provider reference. No payment gateway
     * is configured yet, so that reference is the only evidence the money moved
     * — recording an electronic payment without one would leave billing staff
     * asserting a transfer nobody can check.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'amount_paid' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'payment_method' => ['required', Rule::in(PaymentMethod::values())],
            'reference_number' => [
                Rule::requiredIf(fn (): bool => $this->input('payment_method') === PaymentMethod::Gcash->value),
                'nullable',
                'string',
                'max:100',
                Rule::unique('payments', 'reference_number')->ignore($this->correctingPaymentId),
            ],

            // A correction fixes what was recorded; it is not a way to turn a
            // payment into a failed or refunded one.
            'status' => $this->correctingPaymentId !== null
                ? ['prohibited']
                : ['sometimes', Rule::in(PaymentStatus::values())],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_paid.min' => 'Record the amount actually received.',
            'reference_number.required' => 'A GCash payment needs its reference number.',
            'reference_number.unique' => 'That payment reference has already been recorded.',
        ];
    }
}
