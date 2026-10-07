<?php

namespace App\Http\Requests;

use App\Models\RequestAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The corrected values for a dispatch record: when a unit left, and who took it.
 *
 * Used only to validate a correction, which is why it cannot pass without the
 * allocation it concerns. CorrectionService sets correctingAllocationId from
 * the allocation it has locked, so the time order is judged against that row
 * and not against anything the requester sent.
 */
class CorrectDispatchRequest extends FormRequest
{
    public ?int $correctingAllocationId = null;

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
            'released_at' => ['sometimes', 'date', 'before_or_equal:now'],

            // The same rule release() applies to the name it records.
            'handed_to' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'released_at.before_or_equal' => 'A unit cannot have been dispatched in the future.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if ($this->correctingAllocationId === null) {
                    $validator->errors()->add('released_at', 'A dispatch record can only be validated as a correction to an existing one.');

                    return;
                }

                if (! $this->hasAny(['released_at', 'handed_to'])) {
                    $validator->errors()->add('released_at', 'Send a release time or the name of who took the units.');

                    return;
                }

                if ($validator->errors()->has('released_at') || ! $this->filled('released_at')) {
                    return;
                }

                $allocation = RequestAllocation::query()->find($this->correctingAllocationId);

                if ($allocation === null) {
                    return;
                }

                $releasedAt = CarbonImmutable::parse((string) $this->input('released_at'));

                if ($allocation->allocated_at !== null && $releasedAt->lt($allocation->allocated_at)) {
                    $validator->errors()->add('released_at', 'A unit cannot have left before it was reserved.');
                }

                if ($allocation->received_at !== null && $releasedAt->gt($allocation->received_at)) {
                    $validator->errors()->add('released_at', 'A unit cannot have left after the hospital received it.');
                }
            },
        ];
    }
}
