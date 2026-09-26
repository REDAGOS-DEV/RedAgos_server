<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacilityLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * PNG or JPEG only. WebP is refused, unlike donor avatars, because the
     * logo is also printed on dompdf-rendered reports and dompdf does not
     * reliably render WebP.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'mimetypes:image/png,image/jpeg', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.required' => 'Choose an image to upload.',
            'logo.image' => 'The logo must be an image.',
            'logo.mimes' => 'The logo must be a PNG or JPG file.',
            'logo.mimetypes' => 'The logo must be a PNG or JPG file.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
        ];
    }
}
