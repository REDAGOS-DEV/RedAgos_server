<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The one "change" a payment void carries: that the payment is to be voided.
 *
 * Validated by CorrectionService as every correction's values are. Whether
 * the payment may be voided at all — a manual payment, completed, in a shift
 * still open — is BillingService::assertVoidable()'s question, asked when the
 * void is filed and again when it is approved.
 */
class VoidPaymentRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'void' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'void.accepted' => 'A void request voids the payment.',
        ];
    }
}
