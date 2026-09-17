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
     * Both fields are nullable, and null is a real answer: it means "no
     * clinically approved value yet", which is the state every component ships
     * in. Clearing a shelf life is therefore allowed, and inventory intake goes
     * back to refusing that component rather than falling back to a default.
     *
     * The bounds are sanity limits on what a human can type, not clinical
     * guidance. 3650 days is ten years, comfortably past frozen plasma; nothing
     * in this application decides what a correct shelf life is.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shelf_life_days' => ['present', 'nullable', 'integer', 'min:1', 'max:3650'],
            'storage_temperature' => ['sometimes', 'nullable', 'string', 'max:50'],
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
        ];
    }
}
