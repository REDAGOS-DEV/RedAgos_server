<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking more facilities for what a patient's requirement still has unallocated.
 *
 * The shape only. Whether each facility may be asked, and whether the shares
 * add up to more than is still unallocated, are settled under the
 * requirement's lock by TransfusionAllocationWriter.
 */
class AddTransfusionAllocationsRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return self::shareRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::shareMessages();
    }

    /**
     * The rules for a set of facility shares, shared with the create form.
     *
     * A quantity of nought is accepted and skipped, so a client can send every
     * row of its sourcing table as it stands.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function shareRules(): array
    {
        return [
            // Twenty is a sanity guard, not a network limit.
            'allocations' => ['required', 'array', 'min:1', 'max:20'],
            'allocations.*.facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'allocations.*.lines' => ['required', 'array', 'min:1', 'max:6'],
            'allocations.*.lines.*.component_id' => ['required', 'integer', 'exists:blood_components,id'],
            'allocations.*.lines.*.quantity' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function shareMessages(): array
    {
        return [
            'allocations.required' => 'Choose at least one facility to ask.',
            'allocations.max' => 'Ask no more than twenty facilities at once.',
            'allocations.*.facility_id.required' => 'Choose the facility to ask.',
            'allocations.*.facility_id.exists' => 'That facility cannot receive blood requests.',
            'allocations.*.lines.required' => 'Say how many units to ask this facility for.',
            'allocations.*.lines.*.quantity.max' => 'A single component cannot exceed 100 units.',
        ];
    }
}
