<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDonorAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Please choose an image to upload.',
            'avatar.image' => 'Profile photos must be an image file.',
            'avatar.mimes' => 'Profile photos must be a JPEG, PNG or WebP image.',
            'avatar.max' => 'Profile photos must be 2 MB or smaller.',
        ];
    }
}
