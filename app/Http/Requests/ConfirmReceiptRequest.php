<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmReceiptRequest extends FormRequest
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
     * Omitting allocation_ids confirms every dispatched unit still awaiting
     * confirmation. Naming them supports a delivery that arrived short, where
     * the hospital confirms what it actually received and no more.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'allocation_ids' => ['sometimes', 'array', 'min:1'],
            'allocation_ids.*' => ['integer'],
        ];
    }
}
