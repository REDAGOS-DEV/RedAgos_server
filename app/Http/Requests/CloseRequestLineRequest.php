<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseRequestLineRequest extends FormRequest
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
     * The reason itself is fixed by who is closing — the centre closes what it
     * cannot supply, the hospital what it no longer needs — so only a note is
     * taken here.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
