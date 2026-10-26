<?php

namespace App\Http\Requests;

use App\Service\CashSessionService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Closing a cash shift: the drawer counted, and why it differs if it does.
 *
 * The count may come with the notes and coins behind it; when it does, the
 * service checks they add up to it. Whether the difference needs a note is
 * the service's question, since only it knows what the drawer should hold.
 */
class CloseCashShiftRequest extends FormRequest
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
        return [
            'counted_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'count_breakdown' => ['sometimes', 'nullable', 'array'],
            'count_breakdown.*' => ['integer', 'min:0', 'max:100000'],
            'closing_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Refuse a breakdown that names something other than a peso note or coin.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $breakdown = $this->input('count_breakdown');

            if (! is_array($breakdown)) {
                return;
            }

            foreach (array_keys($breakdown) as $denomination) {
                if (! in_array((string) $denomination, CashSessionService::DENOMINATIONS, true)) {
                    $validator->errors()->add('count_breakdown', "{$denomination} is not a peso note or coin the count lists.");
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'counted_cash.required' => 'Count the drawer and enter the total before closing the shift.',
        ];
    }
}
