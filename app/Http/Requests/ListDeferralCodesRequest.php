<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The centre's deferral codes: for whoever records a deferral, reads one, or
 * keeps the list. What each of them sees of it is DeferralCodeService's call.
 */
class ListDeferralCodesRequest extends FormRequest
{
    /**
     * Any one of these will do, which a route's can: middleware cannot say.
     */
    public const ABILITIES = [
        'deferrals.configure',
        'donors.view_clinical',
        'donors.defer',
        'donations.screen',
        'donations.record_paper',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->canAny(self::ABILITIES);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The Deferral Codes page lists retired codes too; a picker never does.
            'include_retired' => ['sometimes', 'boolean'],
        ];
    }
}
