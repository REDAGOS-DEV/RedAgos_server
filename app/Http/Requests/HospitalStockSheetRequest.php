<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What an Inventory Report is configured to cover (project owner, 2026-10-28).
 */
class HospitalStockSheetRequest extends FormRequest
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
            // One sheet per blood type, or every type on one sheet.
            'by' => ['sometimes', 'string', 'in:type,all'],
            // The component and blood type ids to cover; every one when left out.
            'components' => ['sometimes', 'array', 'min:1', 'max:50'],
            'components.*' => ['integer', 'distinct'],
            'blood_types' => ['sometimes', 'array', 'min:1', 'max:50'],
            'blood_types.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'components.min' => 'Choose at least one component.',
            'blood_types.min' => 'Choose at least one blood type.',
        ];
    }

    /**
     * The layout asked for, one sheet per type unless told otherwise.
     */
    public function layout(): string
    {
        return $this->validated('by') ?? 'type';
    }

    /**
     * The component ids chosen, or null for every component.
     *
     * @return array<int, int>|null
     */
    public function componentIds(): ?array
    {
        return $this->ids('components');
    }

    /**
     * The blood type ids chosen, or null for every type.
     *
     * @return array<int, int>|null
     */
    public function bloodTypeIds(): ?array
    {
        return $this->ids('blood_types');
    }

    /**
     * @return array<int, int>|null
     */
    private function ids(string $key): ?array
    {
        $value = $this->validated($key);

        return $value === null ? null : array_values(array_map('intval', $value));
    }
}
