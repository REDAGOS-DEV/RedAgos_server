<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stock in bags that arrived at a hospital blood bank (project owner, 2026-10-11).
 *
 * Each bag is named by its unit id, with the storage location it goes into:
 * every bag goes onto a shelf, as at a centre's Stock Intake (project owner,
 * 2026-10-30). Whether each bag is this blood bank's and awaiting stock-in is
 * decided by HospitalInventoryService.
 */
class StockInHospitalUnitsRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'units' => ['required', 'array', 'min:1', 'max:100'],
            'units.*' => ['array:unit_id,storage_location'],
            'units.*.unit_id' => ['required', 'string', 'max:50', 'distinct'],
            'units.*.storage_location' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'units.required' => 'Choose the bags to stock in.',
            'units.max' => 'Stock in at most 100 bags at a time.',
            'units.*.unit_id.distinct' => 'A bag is listed twice.',
            'units.*.storage_location.required' => 'Every bag needs a storage location.',
        ];
    }
}
