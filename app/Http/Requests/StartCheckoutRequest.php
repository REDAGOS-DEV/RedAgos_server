<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartCheckoutRequest extends FormRequest
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
     * Only the payer's name: the watcher who will pay, as billing staff read it
     * from them. It goes to Xendit as the customer, because the Payment Session
     * API requires a name, and nothing from the patient record goes with it.
     * There is deliberately no amount — the server takes it from the statement.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'payer_name' => ['required', 'string', 'min:2', 'max:120'],
            'amount' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payer_name.required' => 'Enter the name of the person paying.',
            'amount.prohibited' => 'The amount is taken from the statement, not sent with the checkout.',
        ];
    }
}
