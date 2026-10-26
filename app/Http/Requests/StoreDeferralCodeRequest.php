<?php

namespace App\Http\Requests;

use App\Repository\DeferralCodeRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A deferral code the Screening Physician adds to the centre's list.
 */
class StoreDeferralCodeRequest extends FormRequest
{
    /**
     * Short and plain, so it reads the same on a screen, a printed form and
     * an email: letters and digits, a dash between parts (R13, R2-Low). Kept
     * as typed; told apart from the centre's others whatever the case.
     */
    public const CODE_RULES = ['string', 'max:16', 'regex:/^[A-Za-z0-9]+(-[A-Za-z0-9]+)*$/'];

    public const MESSAGES = [
        'code.required' => 'Give the code, e.g. R13.',
        'code.regex' => 'Use letters and digits only, with a dash between parts if needed, e.g. R13 or R2-Low.',
        'reason.required' => 'Give the reason this code stands for.',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim first, so " R13 " is checked and stored as "R13".
     */
    protected function prepareForValidation(): void
    {
        self::normalise($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', ...self::CODE_RULES],
            'reason' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('code') && app(DeferralCodeRepository::class)->codeTaken(
                    (int) $this->user()?->facility_id,
                    (string) $this->input('code')
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
        return self::MESSAGES;
    }

    /**
     * Shared with UpdateDeferralCodeRequest.
     */
    public static function normalise(FormRequest $request): void
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => trim($request->input('code'))]);
        }

        if (is_string($request->input('reason'))) {
            $request->merge(['reason' => preg_replace('/\s+/', ' ', trim($request->input('reason')))]);
        }
    }
}
