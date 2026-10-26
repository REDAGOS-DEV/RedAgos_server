<?php

namespace App\Http\Requests;

use App\Repository\DeferralCodeRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Change a deferral code's wording, retire it or bring it back. The code
 * itself changes only while no record carries it (DeferralCodeService).
 */
class UpdateDeferralCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        StoreDeferralCodeRequest::normalise($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', ...StoreDeferralCodeRequest::CODE_RULES],
            'reason' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'retired' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->has('code') && ! $validator->errors()->has('code') && app(DeferralCodeRepository::class)->codeTaken(
                    (int) $this->user()?->facility_id,
                    (string) $this->input('code'),
                    (int) $this->route('code')
                )) {
                    $validator->errors()->add('code', 'This centre already has that code, perhaps retired.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StoreDeferralCodeRequest::MESSAGES;
    }
}
