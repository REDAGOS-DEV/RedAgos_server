<?php

namespace App\Service;

use App\Models\BillingDiscount;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * The centre's own list of discounts, kept by the Billing Supervisor and
 * offered at the counter beside the government subsidy (project owner,
 * 2026-11-10).
 *
 * Every read and write is the caller's own centre, from the token: a centre
 * never sees, edits or offers another's discounts. A discount is switched off
 * rather than deleted, because bills it was given to name it.
 */
class BillingDiscountService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @return array{data: array<int, array<string, mixed>>} */
    public function index(User $staff): array
    {
        return [
            'data' => BillingDiscount::query()
                ->with('updater')
                ->where('facility_id', $this->facilityId($staff))
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (BillingDiscount $discount): array => $this->format($discount))
                ->all(),
        ];
    }

    /**
     * What the counter may offer: the centre's discounts that are switched on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function offeredAt(int $facilityId): array
    {
        return BillingDiscount::query()
            ->where('facility_id', $facilityId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (BillingDiscount $discount): array => [
                'id' => $discount->id,
                'name' => $discount->name,
                'kind' => $discount->kind,
                'value' => (float) $discount->value,
                'label' => $discount->describe(),
            ])
            ->all();
    }

    /** @param array<string, mixed> $payload */
    public function create(User $staff, array $payload): array
    {
        $facilityId = $this->facilityId($staff);
        $this->refuseDuplicateName($facilityId, $payload['name']);

        $discount = BillingDiscount::query()->create([
            'facility_id' => $facilityId,
            'name' => $payload['name'],
            'kind' => $payload['kind'],
            'value' => $payload['value'],
            'is_active' => $payload['is_active'] ?? true,
            'created_by' => $staff->id,
            'updated_by' => $staff->id,
        ]);

        $this->auditLogger->record($staff, 'billing.discount_created', $discount, [
            'facility_id' => $facilityId,
            'discount' => $this->snapshot($discount),
        ]);

        return ['message' => "{$discount->name} added.", 'data' => $this->format($discount->load('updater'))];
    }

    /** @param array<string, mixed> $payload */
    public function update(User $staff, int $discountId, array $payload): array
    {
        $facilityId = $this->facilityId($staff);
        $discount = BillingDiscount::query()
            ->where('facility_id', $facilityId)
            ->whereKey($discountId)
            ->first()
            ?? throw $this->refuse(404, 'discount_not_found', 'That discount was not found.');

        $this->refuseDuplicateName($facilityId, $payload['name'], $discount->id);
        $before = $this->snapshot($discount);

        $discount->fill([
            'name' => $payload['name'],
            'kind' => $payload['kind'],
            'value' => $payload['value'],
            'is_active' => $payload['is_active'],
        ]);

        if (! $discount->isDirty()) {
            return ['message' => 'Nothing to change.', 'data' => $this->format($discount->load('updater'))];
        }

        $discount->updated_by = $staff->id;
        $discount->save();

        $this->auditLogger->record($staff, 'billing.discount_updated', $discount, [
            'facility_id' => $facilityId,
            'before' => $before,
            'after' => $this->snapshot($discount),
        ]);

        return ['message' => "{$discount->name} saved.", 'data' => $this->format($discount->load('updater'))];
    }

    /** @return array<string, mixed> */
    private function format(BillingDiscount $discount): array
    {
        return [
            ...$this->snapshot($discount),
            'id' => $discount->id,
            'label' => $discount->describe(),
            'updated_at' => $discount->updated_at?->toIso8601String(),
            'updated_by' => $discount->updater?->signatureName(),
        ];
    }

    /** @return array{name: string, kind: string, value: float, is_active: bool} */
    private function snapshot(BillingDiscount $discount): array
    {
        return [
            'name' => $discount->name,
            'kind' => $discount->kind,
            'value' => (float) $discount->value,
            'is_active' => (bool) $discount->is_active,
        ];
    }

    /** Two discounts the counter could not tell apart, whatever their case. */
    private function refuseDuplicateName(int $facilityId, string $name, ?int $exceptId = null): void
    {
        $taken = BillingDiscount::query()
            ->where('facility_id', $facilityId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'This centre already has a discount by that name.']);
        }
    }

    private function facilityId(User $staff): int
    {
        return $staff->facility_id
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['code' => $code, 'message' => $message], $status));
    }
}
