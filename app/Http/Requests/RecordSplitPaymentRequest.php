<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One payment at the counter split across methods: part cash, the rest by
 * card, GCash or bank transfer (asked for by the project owner on 2026-10-11).
 *
 * Each part is held to the rules of RecordPaymentRequest: every method but
 * cash carries its own reference, only cash takes an amount tendered, and only
 * card takes additional information (the cardholder's last name). The
 * cash is counted once, so at most one part is cash. Whether the parts fit the
 * balance is decided by BillingService, which records them in order against
 * the locked statement.
 */
class RecordSplitPaymentRequest extends FormRequest
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
            'tenders' => ['required', 'array', 'min:2', 'max:4'],
            'tenders.*' => ['array:amount_paid,payment_method,reference_number,amount_tendered,additional_information'],
            'tenders.*.amount_paid' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'tenders.*.payment_method' => ['required', Rule::in(PaymentMethod::values())],
            'tenders.*.reference_number' => [
                'required_unless:tenders.*.payment_method,'.PaymentMethod::Cash->value,
                'nullable',
                'string',
                'max:100',
                'distinct',
                Rule::unique('payments', 'reference_number'),
            ],
            'tenders.*.amount_tendered' => [
                'prohibited_unless:tenders.*.payment_method,'.PaymentMethod::Cash->value,
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:0.01',
                'max:9999999.99',
            ],
            'tenders.*.additional_information' => [
                'prohibited_unless:tenders.*.payment_method,'.PaymentMethod::Card->value,
                'nullable',
                'string',
                'max:100',
            ],
            'status' => ['prohibited'],

            // Who handed the money over, printed on the one receipt for every part.
            'payer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * The cash in a split is one count of the drawer, not several.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $cashParts = collect($this->input('tenders', []))
                    ->filter(fn ($tender): bool => is_array($tender) && ($tender['payment_method'] ?? null) === PaymentMethod::Cash->value)
                    ->count();

                if ($cashParts > 1) {
                    $validator->errors()->add('tenders', 'Count the cash once: a split payment has at most one cash part.');
                }
            },
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
            'tenders.min' => 'A split payment has at least two parts; record a single payment instead.',
            'tenders.max' => 'A split payment has at most four parts.',
            'tenders.*.amount_paid.decimal' => 'Record each amount in pesos and centavos, with at most two decimal places.',
            'tenders.*.amount_paid.min' => 'Record the amount actually received.',
            'tenders.*.reference_number.required_unless' => 'A GCash, card or bank-transfer payment needs its reference number.',
            'tenders.*.reference_number.distinct' => 'Each part needs its own reference.',
            'tenders.*.reference_number.unique' => 'That payment reference has already been recorded.',
            'tenders.*.amount_tendered.prohibited_unless' => 'Only a cash payment has an amount tendered.',
            'tenders.*.additional_information.prohibited_unless' => 'Only a card payment carries additional information.',
            'status.prohibited' => 'A recorded payment is money received; its status cannot be chosen.',
        ];
    }
}
