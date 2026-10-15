<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBloodComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The shelf life is nullable, and null is a real answer: it means "no
     * approved value yet for this facility", which is the state every component
     * starts in. Clearing a shelf life is therefore allowed, and stock intake
     * goes back to refusing that component rather than defaulting an expiry.
     *
     * The bounds are sanity limits on what a human can type, not clinical
     * guidance. 3650 days is ten years, comfortably past frozen plasma; nothing
     * in this application decides what a correct shelf life is.
     *
     * The price is refused rather than ignored. It is the Billing
     * department's, set through /component-prices, and a client still sending
     * it here should hear that it was not saved.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shelf_life_days' => ['present', 'nullable', 'integer', 'min:1', 'max:3650'],
            'price' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shelf_life_days.present' => 'Send a shelf life, or null to leave it unconfigured.',
            'shelf_life_days.min' => 'A shelf life of less than one day cannot be recorded.',
            'price.prohibited' => 'Prices are set by Billing, under Component Prices.',
        ];
    }
}
