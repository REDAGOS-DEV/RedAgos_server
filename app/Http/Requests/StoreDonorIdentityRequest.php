<?php

namespace App\Http\Requests;

use App\Enums\ValidIdType;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDonorIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the ID number before validating so the unique rule compares
     * like for like.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('valid_id_number')) {
            $this->merge([
                'valid_id_number' => AccountIdentity::normalizeValidIdNumber(
                    $this->input('valid_id_number')
                ),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'valid_id_type' => ['required', 'string', Rule::in(ValidIdType::values())],

            // Scoped to donor_id, which is donor_profiles' primary key: a donor
            // correcting a typo must not collide with the number they already
            // hold.
            'valid_id_number' => [
                'required', 'string', 'max:50',
                Rule::unique('donor_profiles', 'valid_id_number')->ignore($this->user()?->id, 'donor_id'),
            ],

            // Larger than the 2 MB avatar cap: the number and the photograph on
            // an ID have to stay legible to the administrator reviewing it.
            'valid_id_image' => [
                'required', 'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:4096',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valid_id_type.required' => 'Please select which ID you are submitting.',
            'valid_id_type.in' => 'Please select a valid ID type.',
            'valid_id_number.required' => 'Please enter the number on your ID.',
            'valid_id_number.unique' => 'This ID is already on file. Please contact support.',
            'valid_id_image.required' => 'Please attach a photo of your ID.',
            'valid_id_image.image' => 'Your ID must be an image file.',
            'valid_id_image.mimes' => 'Your ID must be a JPEG, PNG or WebP image.',
            'valid_id_image.max' => 'Your ID photo must be 4 MB or smaller.',
        ];
    }
}
