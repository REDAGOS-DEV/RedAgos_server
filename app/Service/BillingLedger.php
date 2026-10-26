<?php

namespace App\Service;

use App\Enums\BillingStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionCategory;
use App\Enums\TransactionChannel;
use App\Enums\TransactionType;
use App\Models\Billing;
use App\Models\BillingRevision;
use App\Models\BillingTransaction;
use App\Models\BloodRequest;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Repository\DocumentSequenceRepository;
use App\Support\DocumentNumbering;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The billing journal's one writer: a numbered, categorised row per money event.
 *
 * Every change BillingService makes to what a bill charges or has collected
 * posts here, inside the same transaction and under the same locks, so the
 * journal can never say something the bill does not. The bill and its
 * payments stay what the release gate reads; the journal is the record of
 * how they got there.
 *
 * Numbering. The TXN- counter is taken after any statement (SOA-) and receipt
 * (AR-) number in the same transaction. Every writer takes counters in that
 * one order — statement, receipt, transaction — so two never wait on each
 * other the other way round.
 */
class BillingLedger
{
    public function __construct(
        private readonly DocumentSequenceRepository $sequences
    ) {}

    /**
     * Post one money event against a statement locked under BillingService::lockForMutation()'s order.
     *
     * @param  int  $amount  Centavos, signed against the bill: positive raises what is owed, negative settles it.
     */
    public function post(
        Billing $lockedBilling,
        BloodRequest $request,
        TransactionType $type,
        TransactionChannel $channel,
        int $amount,
        ?User $actor = null,
        ?Payment $payment = null,
        ?PaymentReceipt $receipt = null,
        ?BillingRevision $revision = null,
        ?PaymentAttempt $attempt = null,
        ?int $cashSessionId = null,
        ?int $correctionRequestId = null,
        ?BillingTransaction $reverses = null,
        ?string $reference = null,
        ?string $note = null,
        ?PaymentMethod $paymentMethod = null,
    ): BillingTransaction {
        $facilityId = (int) $request->target_facility_id;

        $previous = BillingTransaction::query()
            ->where('billing_id', $lockedBilling->id)
            ->orderByDesc('id')
            ->value('balance_after');

        $balance = Money::toCentavos($previous ?? '0.00') + $amount;

        return BillingTransaction::query()->create([
            'transaction_number' => DocumentNumbering::format('TXN', $facilityId, $this->sequences->next($facilityId, 'transaction')),
            'facility_id' => $facilityId,
            'billing_id' => $lockedBilling->id,
            'request_id' => $request->id,
            'category' => TransactionCategory::forRequest($request),
            'type' => $type,
            'channel' => $channel,
            'payment_method' => $paymentMethod ?? $payment?->payment_method,
            'amount' => Money::toDecimal($amount),
            'balance_after' => Money::toDecimal($balance),
            'payment_id' => $payment?->id,
            'payment_receipt_id' => $receipt?->id,
            'billing_revision_id' => $revision?->id ?? $payment?->billing_revision_id,
            'payment_attempt_id' => $attempt?->id ?? $payment?->payment_attempt_id,
            // Never taken from the payment: a correction approved after its
            // shift closed belongs to no shift, or the closed drawer's reading
            // would change after it was counted.
            'cash_session_id' => $cashSessionId,
            'correction_request_id' => $correctionRequestId,
            'reverses_transaction_id' => $reverses?->id,
            'reference' => $reference === null ? null : mb_substr($reference, 0, 100),
            'note' => $note === null ? null : mb_substr($note, 0, 500),
            'recorded_by' => $actor?->id,
            'occurred_at' => now(),
        ]);
    }

    /**
     * What a statement's journal must add up to, in centavos.
     *
     * Its total less what it has collected. A subsidised bill's total is zero,
     * so anything collected before the subsidy reads as a credit owed back
     * outside RedAgos. A weekly bill the hospital settled owes nothing.
     */
    public function expectedBalance(Billing $billing, int $collected): int
    {
        if ($billing->status === BillingStatus::SettledOutside) {
            return 0;
        }

        return Money::toCentavos($billing->total_amount) - $collected;
    }

    /**
     * The statements whose journal does not add up to what they owe.
     *
     * Empty when the journal and the bills agree, which they must; anything
     * here is a defect to investigate, never to correct by hand.
     *
     * @return Collection<int, array{billing_id: int, request_id: int, journal: int, expected: int}>
     */
    public function drift(StatementFigures $figures, ?int $facilityId = null): Collection
    {
        return Billing::query()
            ->with('request:id,target_facility_id')
            ->when($facilityId !== null, fn ($query) => $query->whereHas(
                'request',
                fn ($request) => $request->where('target_facility_id', $facilityId)
            ))
            ->orderBy('id')
            ->get()
            ->map(function (Billing $billing) use ($figures): array {
                $journal = (int) BillingTransaction::query()
                    ->where('billing_id', $billing->id)
                    ->pluck('amount')
                    ->sum(fn (int|float|string $amount): int => Money::toCentavos($amount));

                return [
                    'billing_id' => $billing->id,
                    'request_id' => (int) $billing->request_id,
                    'journal' => $journal,
                    'expected' => $this->expectedBalance($billing, $figures->collectedFor($billing)),
                ];
            })
            ->filter(fn (array $row): bool => $row['journal'] !== $row['expected'])
            ->values();
    }
}
