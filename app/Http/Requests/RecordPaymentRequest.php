<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
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
     * At most two decimal places: an amount is pesos and centavos, and
     * BillingService counts in whole centavos.
     *
     * The status is never chosen. A payment recorded by hand is money already
     * received, so it is always completed; an attempt that may still fail
     * belongs to a payment provider, not to this form. Decided by the project
     * owner on 2026-10-10. A correction is held to the same rule: it fixes
     * what was recorded, not whether the money arrived.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'amount_paid' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'payment_method' => ['required', Rule::in(PaymentMethod::values())],
            'reference_number' => [
                Rule::requiredIf(fn (): bool => $this->input('payment_method') === PaymentMethod::Gcash->value),
                'nullable',
                'string',
                'max:100',
                Rule::unique('payments', 'reference_number')->ignore($this->correctingPaymentId),
            ],
            'status' => ['prohibited'],

            // Who handed the money over — usually the patient's watcher. Printed
            // on the receipt as "Received from", and nowhere else.
            'payer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
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
            'amount_paid.decimal' => 'Record the amount in pesos and centavos, with at most two decimal places.',
            'amount_paid.min' => 'Record the amount actually received.',
            'reference_number.required' => 'A GCash payment needs its reference number.',
            'reference_number.unique' => 'That payment reference has already been recorded.',
            'status.prohibited' => 'A recorded payment is money received; its status cannot be chosen.',
        ];
    }
}
