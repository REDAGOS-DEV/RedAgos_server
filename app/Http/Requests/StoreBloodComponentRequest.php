<?php

namespace App\Http\Requests;

use App\Repository\BloodComponentRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A component Processing adds to the shared catalogue.
 *
 * The catalogue is network-wide, so a name or code added here is taken for
 * every centre. Both are compared case-insensitively and against retired
 * components too, which keep their rows.
 */
class StoreBloodComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise first, so "  packed  rbc " is checked as "packed rbc" and a
     * code is stored the way the seeded ones are, in capitals.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => preg_replace('/\s+/', ' ', trim($this->input('name')))]);
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }

        if (is_string($this->input('storage_temperature'))) {
            $this->merge(['storage_temperature' => trim($this->input('storage_temperature')) ?: null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],

            // The bag-number suffix: 1234567-PRBC. Required for a new
            // component, so a bag is never numbered from initials that could
            // match another component's. Starts with a letter, because a
            // second bag of the same component is numbered -2, -3.
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z][A-Z0-9]*$/'],

            'storage_temperature' => ['nullable', 'string', 'max:50'],

            // This centre's shelf life, set in the same step. The same bounds
            // as UpdateBloodComponentRequest: sanity limits, not guidance.
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $components = app(BloodComponentRepository::class);

                if (! $validator->errors()->has('name') && $components->nameTaken((string) $this->input('name'))) {
                    $validator->errors()->add('name', 'A component with this name already exists.');
                }

                if (! $validator->errors()->has('code') && $components->codeTaken((string) $this->input('code'))) {
                    $validator->errors()->add('code', 'Another component already uses this code.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use letters and digits only, starting with a letter, e.g. PRBC.',
            'shelf_life_days.min' => 'A shelf life of less than one day cannot be recorded.',
        ];
    }
}
