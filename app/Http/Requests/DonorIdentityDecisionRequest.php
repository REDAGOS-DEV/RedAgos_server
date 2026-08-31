<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DonorIdentityDecisionRequest extends FormRequest
{
    /**
     * The named route that carries a rejection.
     *
     * The name is load-bearing: routeIs() returns false on an unnamed route, so
     * dropping the name would validate every decision as an approval and make
     * `reason` prohibited on the endpoint that requires it.
     */
    private const REJECT_ROUTE = 'admin.donor-identities.reject';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Both decisions share this request, so the reason is required on one route
     * and refused on the other rather than being loosely optional on both.
     *
     * A rejection reason posted to the approve endpoint is a client bug worth
     * surfacing, not something to swallow.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => $this->isRejection()
                ? ['required', 'string', 'max:255']
                : ['prohibited'],

            // The version the administrator actually reviewed. Compared under
            // the row lock so a document replaced mid-review cannot be approved
            // on the strength of the one before it.
            'submission_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required so the donor can be told why.',
            'reason.prohibited' => 'An approval does not carry a reason.',
            'submission_version.required' => 'The submission being decided on must be identified.',
        ];
    }

    /**
     * Determine whether this request is rejecting the submission.
     */
    public function isRejection(): bool
    {
        return $this->routeIs(self::REJECT_ROUTE);
    }
}
