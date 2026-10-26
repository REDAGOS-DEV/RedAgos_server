<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveComponentPricesRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every line is either a price above zero or an explicit no charge, never
     * a blank. A free component used to be one left unpriced, which could not
     * be told apart from one nobody had got to yet (project owner, 2026-10-15).
     * Whether every component is covered is checked by the service, against
     * the catalogue as it stands when the save lands.
     *
     * The upper bound is a sanity limit on what a human can type, not
     * commercial guidance.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prices' => ['required', 'array', 'min:1', 'max:200'],
            'prices.*' => ['required', 'array'],
            'prices.*.component_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('blood_components', 'id')->whereNull('deleted_at'),
            ],
            'prices.*.no_charge' => ['required', 'boolean'],
            'prices.*.price' => [
                'prohibited_if:prices.*.no_charge,true',
                'required_if:prices.*.no_charge,false',
                'nullable',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:1000000',
            ],
            'prices.*.shelf_life_days' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prices.*.component_id.distinct' => 'Each component may appear only once.',
            'prices.*.component_id.exists' => 'That blood component is no longer available.',
            'prices.*.price.required_if' => 'Enter a price, or mark the component as no charge.',
            'prices.*.price.prohibited_if' => 'A component marked no charge cannot also have a price.',
            'prices.*.price.decimal' => 'A price can have at most two decimal places.',
            'prices.*.price.gt' => 'A price must be above ₱0. Mark the component as no charge instead.',
            'prices.*.shelf_life_days.prohibited' => 'Shelf life is set by Processing, under Blood Components.',
        ];
    }
}
