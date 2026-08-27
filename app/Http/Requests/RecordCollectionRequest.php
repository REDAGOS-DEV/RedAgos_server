<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `collected_by` is absent by design: it is the authenticated staff member,
     * never a name supplied by the request, because it is the traceability link
     * between a bag and the person who drew it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A whole-blood bag is 450 mL nominal; the range is wide enough for
            // a short draw or a double without asserting a clinical rule nobody
            // has owned. See the clinical-configuration boundary decision.
            'volume_ml' => ['required', 'integer', 'min:100', 'max:1000'],
            'collection_datetime' => ['sometimes', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collection_datetime.before_or_equal' => 'A collection cannot be recorded in the future.',
        ];
    }
}
