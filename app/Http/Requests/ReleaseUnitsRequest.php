<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseUnitsRequest extends FormRequest
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
     * Omitting allocation_ids releases everything currently held, which is the
     * common case. Naming specific holds supports a partial dispatch, where a
     * courier takes what is ready and the rest follows.
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
