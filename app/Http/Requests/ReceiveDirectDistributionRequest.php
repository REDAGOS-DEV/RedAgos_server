<?php

namespace App\Http\Requests;

use App\Support\OperationalDay;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Blood received from outside RedAgos: one bag for a Patient Transfusion
 * Request, or a typed batch — one identifier, how many bags, and who asked for
 * them — without one.
 *
 * The external unit number is normalised the way a scanned barcode is
 * (whitespace and control characters stripped, uppercased), so a scanned and a
 * typed copy of one bag are the same number.
 *
 * Whether the bag was already received is the service's question: it depends
 * on the source and on what the database holds.
 */
class ReceiveDirectDistributionRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the typed or scanned number before it is validated.
     */
    protected function prepareForValidation(): void
    {
        $number = $this->input('external_unit_number');

        if (is_string($number)) {
            $this->merge([
                'external_unit_number' => strtoupper((string) preg_replace('/[\s\p{Cc}]+/u', '', $number)),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Expiry is held to the operational today, as at a centre's intake (D8):
     * a bag already past its date cannot be booked into stock.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'transfusion_request_id' => ['nullable', 'integer', 'required_without:requested_for'],
            'requested_for' => ['nullable', 'string', 'max:150', 'required_without:transfusion_request_id'],
            // A bag scanned for a patient is one bag; only a typed batch says how many.
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'external_blood_source_id' => ['required', 'integer', 'exists:external_blood_sources,id'],
            'external_unit_number' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9-]+$/'],
            'blood_type_id' => ['required', 'integer', 'exists:blood_types,id'],
            'component_id' => ['required', 'integer', 'exists:blood_components,id'],
            'volume_ml' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'collection_date' => ['nullable', 'date', 'before_or_equal:'.OperationalDay::todayAsDate()],
            'expiry_date' => ['required', 'date', 'after_or_equal:'.OperationalDay::todayAsDate(), 'after:collection_date'],
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * Refuse a count on a receipt for a patient's request, which is one bag at a time.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('transfusion_request_id') && (int) $this->input('quantity', 1) > 1) {
                $validator->errors()->add('quantity', 'A bag received for a patient transfusion request is received one at a time.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'transfusion_request_id.required_without' => 'Choose the patient transfusion request, or say who the blood was requested for.',
            'requested_for.required_without' => 'Say who requested the blood — the patient, ward or physician.',
            'quantity.min' => 'At least one unit must have been received.',
            'quantity.max' => 'Record at most 100 units at a time.',
            'external_blood_source_id.required' => 'Choose the blood service that sent the bag.',
            'external_unit_number.required' => 'Scan or type the number printed on the bag.',
            'external_unit_number.regex' => 'A unit number may contain only letters, numbers and dashes.',
            'external_unit_number.max' => 'A unit number cannot be longer than 50 characters.',
            'blood_type_id.required' => 'Select the blood type on the bag.',
            'component_id.required' => 'Select the component in the bag.',
            'volume_ml.max' => 'A volume cannot exceed 1000 mL.',
            'collection_date.before_or_equal' => 'The collection date cannot be in the future.',
            'expiry_date.required' => 'Enter the expiry date printed on the bag.',
            'expiry_date.after_or_equal' => 'This bag is already past its expiry date and cannot be received into stock.',
            'expiry_date.after' => 'The expiry date must be after the collection date.',
            'received_at.before_or_equal' => 'The bag cannot have been received in the future.',
        ];
    }
}
