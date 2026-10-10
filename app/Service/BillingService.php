<?php

namespace App\Service;

use App\Enums\BillingStatus;
use App\Enums\CorrectionSubject;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Enums\RequestPurpose;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Models\CorrectionRequest;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Repository\BloodRequestRepository;
use App\Support\CorrectionValues;
use App\Support\DocumentNumbering;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The statement raised against a blood request, and what it collects.
 *
 * Blood requests are presently funded by government subsidy, so every
 * statement this raises comes to zero and is settled on creation. That is a
 * pricing fact, not an architectural one: the charge is computed from
 * the fulfilling facility's own price for the component, which is unset today.
 * Setting it turns the release gate on by itself, with no change here or in
 * FulfillmentService — which is why the gate is written and tested now rather
 * than deferred until money appears. It is held per facility so that one centre
 * pricing its components cannot start blocking releases at the other three.
 *
 * Who owes. Only the patient or watcher of a Patient Transfusion owes money in
 * RedAgos (project owner, 2026-10-10). A weekly (replenishment) order is
 * raised as StatementOnly: the hospital receives the statement, nothing is
 * collected against it here, and it does not hold units back.
 *
 * Locking. Every change to a statement or to one of its payments runs inside
 * one transaction, and the blood request is the point they serialise on:
 * locks are always taken request, then billing, then attempt or payment, then
 * a document-number counter last. That is the order release() already holds
 * the request before it reads the statement, so a payment, a subsidy, a
 * reallocation, a correction, a checkout, a gateway confirmation and a release
 * on one request take turns instead of overwriting each other. A statement's
 * status is always worked out from the billing row and payments as they stand
 * under the lock, never from a model loaded before it — a stale one would put
 * back a status another writer had just changed. lockForMutation() takes the
 * first two locks.
 *
 * Money. Every total, sum and comparison here is in whole centavos (see
 * App\Support\Money), never a float. Amounts go back to decimals only to be
 * stored, and to floats only where an existing response or audit field has
 * always been a JSON number.
 */
class BillingService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly StatementFigures $figures,
        private readonly StatementRevisionService $statements,
        private readonly PaymentReceiptService $receipts
    ) {}

    /**
     * Lock a request and its statement for a change, in the one order every writer uses.
     *
     * Scoped to the facility the request is addressed to, so another centre's
     * request is a 404. Must be called inside a transaction: outside one the
     * locks are released the moment each query returns.
     *
     * @return array{0: BloodRequest, 1: Billing}
     */
    public function lockForMutation(int $requestId, int $facilityId): array
    {
        $request = $this->bloodRequestRepository->lockAddressedTo($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        $billing = Billing::query()
            ->where('request_id', $request->id)
            ->lockForUpdate()
            ->first()
            ?? throw $this->refuse(404, 'billing_missing', 'No statement has been raised for this request yet.');

        return [$request, $billing];
    }

    /**
     * Raise or update the statement for a request's currently held units.
     *
     * Called on every allocation, because billings.request_id is unique: a
     * request that grows from two held units to five needs its one statement
     * to grow with it rather than a second statement it cannot have.
     *
     * The held units are read from the allocations rather than passed in. Once
     * a request can ask for several components at different prices, a bare
     * number of units no longer determines what is owed — five units of
     * platelets and five of packed cells are two different totals.
     *
     * A total that changes while a gateway checkout is open supersedes that
     * checkout: the payer would otherwise be paying a figure the statement no
     * longer shows. One already awaiting verification is left alone, because
     * money may already be on its way.
     *
     * Must be called inside a transaction that already holds the request lock,
     * as the allocating one does. The statement is then locked here, which
     * completes request, then billing.
     */
    public function syncFor(BloodRequest $request, User $actor): Billing
    {
        $lines = $this->figures->linesFor($request);
        $total = $this->figures->totalOf($lines);

        $billing = $request->billing()->lockForUpdate()->first();

        if (! $billing) {
            $billing = Billing::query()->create([
                'request_id' => $request->id,
                'billed_by' => $actor->id,
                'billing_date' => now(),
                'total_amount' => Money::toDecimal($total),
                'status' => $this->collectsPayment($request)
                    ? $this->settlementFor($total, 0)
                    : BillingStatus::StatementOnly,
            ]);

            $this->auditLogger->record($actor, 'billing.raised', $billing, [
                'request_id' => $request->id,
                'total_amount' => Money::toFloat($total),
                'units' => $this->figures->unitsOf($lines),
                'subsidised' => $total === 0,
                'statement_only' => $billing->status === BillingStatus::StatementOnly,
            ]);

            return $billing;
        }

        // A statement settled by decision is left alone. Voiding says it
        // should never have existed; subsidy says the government met the cost.
        // Either way somebody decided nothing is owed, and silently re-pricing
        // because another unit was allocated would undo that — and would
        // re-block release on a request already cleared to go.
        if ($billing->status->isSettledByDecision()) {
            return $billing;
        }

        $changed = Money::toCentavos($billing->total_amount) !== $total;

        $billing->total_amount = Money::toDecimal($total);
        $collected = $this->applyCollected($billing);

        if ($changed) {
            $this->supersedeOpenCheckouts($billing, 'statement_changed');
        }

        $this->auditLogger->record($actor, 'billing.updated', $billing, [
            'request_id' => $request->id,
            'total_amount' => Money::toFloat($total),
            'collected' => Money::toFloat($collected),
            'units' => $this->figures->unitsOf($lines),
        ]);

        return $billing;
    }

    /**
     * List the statements raised against one facility's incoming requests.
     *
     * Scoped through the request's target facility, exactly as show() is: a
     * statement belongs to the centre that raised it, and billing staff at one
     * centre have no business reading another's.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return Billing::query()
            ->whereHas('request', fn (Builder $query) => $query->addressedTo($facilityId))
            ->with(['request.requestingFacility', 'request.items.component', 'request.bloodType'])
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['outstanding']) && $filters['outstanding'],
                fn (Builder $query): Builder => $query->outstanding()
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query): Builder => $query->whereHas(
                    'request',
                    fn (Builder $request): Builder => $request->where(
                        'reference_number', 'like', '%'.$filters['search'].'%'
                    )
                )
            )
            ->latest('billing_date')
            ->paginate($perPage)
            ->through(fn (Billing $billing): array => $this->formatWithRequest($billing));
    }

    /**
     * Project a statement together with enough of its request to be actionable.
     *
     * The billing queue is a list of statements, but a billing officer settles
     * them by reading the request: whose it is, what was asked for, and how
     * much of it is held. Those come from the request, not the statement.
     *
     * @return array<string, mixed>
     */
    public function formatWithRequest(Billing $billing): array
    {
        $request = $billing->request;

        return $this->format($billing) + [
            'request' => $request ? [
                'id' => $request->id,
                'reference_number' => $request->reference_number,
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'request_purpose' => $request->request_purpose->value,
                'requesting_facility' => $request->requestingFacility?->name,
                'blood_type' => $request->bloodType?->code,
                'quantity' => $request->quantity,
                'urgency_level' => $request->urgency_level->value,
                'is_emergency' => $request->urgency_level->isPrioritised(),
                'components' => $request->items
                    ->map(fn ($item): string => trim(($item->component?->name ?? 'Component').' x'.$item->quantity))
                    ->all(),
                'request_date' => $request->request_date?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Meet a statement from the government subsidy rather than charging for it.
     *
     * Zeroes the balance and clears the request for release without recording a
     * payment, because none was taken. Any money already collected is left
     * alone: a part-paid statement that is then subsidised has a refund to
     * settle outside this system, and quietly deleting the payment rows would
     * destroy the only record that it was ever received.
     *
     * A statement-only one has nothing collected in RedAgos to waive, and a
     * statement with a checkout open could be paid while it is being waived;
     * both are refused.
     *
     * @return array<string, mixed>
     */
    public function applySubsidy(User $actor, Billing $billing, ?string $reason = null): array
    {
        return DB::transaction(function () use ($actor, $billing, $reason): array {
            // The statement the caller loaded may be out of date by now. Every
            // check and every figure below reads the locked row instead.
            [, $statement] = $this->lockForMutation((int) $billing->request_id, (int) $actor->facility_id);

            if ($statement->status === BillingStatus::Subsidised) {
                throw $this->refuse(
                    409,
                    'billing_already_subsidised',
                    'This statement is already covered by the government subsidy.'
                );
            }

            if ($statement->status === BillingStatus::Void) {
                throw $this->refuse(
                    409,
                    'billing_void',
                    'This statement has been voided and cannot be subsidised.'
                );
            }

            if ($statement->status === BillingStatus::StatementOnly) {
                throw $this->refuse(
                    409,
                    'billing_not_collectible',
                    'This weekly order is billed by statement only; there is nothing collected in RedAgos to waive.'
                );
            }

            $this->assertNoOpenCheckout($statement);

            $charged = Money::toCentavos($statement->total_amount);
            $collected = $this->collectedFor($statement);

            $statement->total_amount = 0;
            $statement->status = BillingStatus::Subsidised;
            $statement->save();

            $this->auditLogger->record($actor, 'billing.subsidised', $statement, array_filter([
                'request_id' => $statement->request_id,
                // The figure that was waived, kept because the statement no longer
                // carries it and a subsidy nobody can size cannot be reported on.
                'amount_waived' => Money::toFloat($charged),
                'already_collected' => Money::toFloat($collected),
                'reason' => $reason,
            ], fn ($value): bool => $value !== null));

            return [
                'message' => $collected > 0
                    ? 'Statement covered by the government subsidy. Payments already recorded are unchanged.'
                    : 'Statement covered by the government subsidy.',
                'billing' => $this->format($statement->fresh()),
            ];
        });
    }

    /**
     * Refuse release unless the request's statement is settled.
     *
     * The Capstone states twice that no unit leaves without confirmed payment.
     * Under the present subsidy every statement is zero and already paid, so
     * this passes silently — but it is the real gate, not a placeholder, and it
     * starts refusing the moment a component carries a price. A weekly order's
     * statement-only statement clears it, by owner decision.
     *
     * Reads the statement without locking it: its caller, release(), already
     * holds the request, and every writer takes the request first, so what it
     * reads cannot change underneath it. The gate is judged at the moment of
     * dispatch. A payment correction approved afterwards can reopen the
     * balance, and that is surfaced as outstanding rather than undone.
     */
    public function assertClearsRelease(BloodRequest $request): void
    {
        $billing = $request->billing()->first();

        if (! $billing) {
            throw $this->refuse(
                409,
                'billing_missing',
                'No statement has been raised for this request, so it cannot be released.'
            );
        }

        if (! $billing->clearsRelease()) {
            throw $this->refuse(
                409,
                'billing_unsettled',
                'Blood units cannot be released until payment for this request is confirmed.'
            );
        }
    }

    /**
     * Issue a Statement of Account for one of this centre's requests, or show the current one again.
     *
     * Freezes the statement as it stands into a numbered revision. Issuing
     * again while nothing has changed hands back the same revision rather than
     * consuming another number.
     *
     * @return array<string, mixed>
     */
    public function issueStatement(User $staff, int $requestId): array
    {
        [$revision, $created] = DocumentNumbering::transaction(function () use ($staff, $requestId): array {
            [$request, $billing] = $this->lockForMutation($requestId, (int) $staff->facility_id);

            if ($billing->status === BillingStatus::Void) {
                throw $this->refuse(409, 'billing_void', 'This statement has been voided, so no statement can be issued for it.');
            }

            return $this->statements->issueOrReuse($request, $billing, $staff, 'statement');
        });

        if ($created) {
            $this->auditLogger->record($staff, 'billing.statement_issued', $revision->billing, [
                'revision_id' => $revision->id,
                'document_number' => $revision->document_number,
                'revision_number' => $revision->revision_number,
            ]);
        }

        return [
            'message' => $created
                ? 'Statement issued.'
                : 'Nothing has changed since the last statement, so it is shown again.',
            'created' => $created,
            'revision' => $this->statements->format($revision),
        ];
    }

    /**
     * Record money billing staff received against a statement.
     *
     * Always a completed, manual payment naming its recorder: an attempt that
     * may still fail is a payment provider's, not this form's. Refused when
     * the statement takes no payment at all (see assertAcceptsPayment()), and
     * while a gateway checkout is open on it, which could collect the same
     * balance a second time.
     *
     * Pinned to the statement revision it was made against — the latest, or a
     * new one if the bill or balance has moved since — and issued its receipt
     * in the same transaction.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recordPayment(User $actor, Billing $billing, array $payload): array
    {
        return DocumentNumbering::transaction(function () use ($actor, $billing, $payload): array {
            // Worked out from the locked statement, not the one the caller
            // loaded: a correction approved a moment ago may have changed
            // what has been collected, and this must not put the old status back.
            [$request, $statement] = $this->lockForMutation((int) $billing->request_id, (int) $actor->facility_id);

            $this->assertAcceptsPayment($statement);
            $this->assertNoOpenCheckout($statement);

            $balanceBefore = $this->outstandingFor($statement);
            [$revision] = $this->statements->issueOrReuse($request, $statement, $actor, 'payment');

            $payment = Payment::query()->create([
                'billing_id' => $statement->id,
                'billing_revision_id' => $revision->id,
                'amount_paid' => Money::toDecimal(Money::toCentavos($payload['amount_paid'])),
                'payment_method' => $payload['payment_method'],
                'reference_number' => $payload['reference_number'] ?? null,
                'status' => PaymentStatus::Completed,
                'source' => PaymentSource::Manual,
                'recorded_by' => $actor->id,
                'payment_date' => now(),
            ]);

            $collected = $this->applyCollected($statement);

            $receipt = $this->receipts->issue(
                $payment,
                $request,
                $revision,
                $balanceBefore,
                $actor,
                $payload['payer_name'] ?? null
            );

            $this->auditLogger->record($actor, 'billing.payment_recorded', $statement, [
                'payment_id' => $payment->id,
                'amount_paid' => Money::toFloat(Money::toCentavos($payment->amount_paid)),
                'payment_method' => $payment->payment_method->value,
                'collected' => Money::toFloat($collected),
                'status' => $statement->status->value,
                'receipt_number' => $receipt->receipt_number,
            ]);

            return [
                'message' => 'Payment recorded.',
                'billing' => $this->format($statement->fresh()),
                'receipt' => $this->receipts->format($receipt),
            ];
        });
    }

    /**
     * Record the payment a verified gateway checkout collected, exactly once.
     *
     * Called only after the server re-fetched the provider's session and found
     * it completed for this attempt's reference, currency and amount. Locks the
     * request, the statement and the attempt in that order; an attempt already
     * completed is left as it is, so a repeated or late confirmation never
     * records the money twice (payments.payment_attempt_id is unique as well).
     *
     * Money that moved is always recorded. If the statement was decided, settled
     * or changed while the checkout was open, the payment still goes in, the
     * decided status is kept, and the attempt is flagged for a person to review
     * — a refund or an overpayment is theirs to settle.
     */
    public function confirmGatewayPayment(PaymentAttempt $attempt, string $providerPaymentId): ?Payment
    {
        $attempt->loadMissing('billing.request');
        $request = $attempt->billing->request;

        return DocumentNumbering::transaction(function () use ($attempt, $request, $providerPaymentId): ?Payment {
            [$lockedRequest, $statement] = $this->lockForMutation((int) $request->id, (int) $request->target_facility_id);

            $locked = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentAttemptStatus::Completed) {
                return $locked->payment;
            }

            $balanceBefore = Money::toCentavos($statement->total_amount) - $this->collectedFor($statement);

            $reviewReason = match (true) {
                ! $statement->status->isCollectible() => 'statement_settled_by_decision',
                $balanceBefore <= 0 => 'nothing_outstanding',
                ! $locked->status->isOpen() => 'late_completion',
                $balanceBefore !== $locked->amount_centavos => 'statement_changed',
                default => null,
            };

            $payment = Payment::query()->create([
                'billing_id' => $statement->id,
                'billing_revision_id' => $locked->billing_revision_id,
                'payment_attempt_id' => $locked->id,
                'amount_paid' => Money::toDecimal($locked->amount_centavos),
                'payment_method' => PaymentMethod::Gcash,
                'reference_number' => $providerPaymentId,
                'status' => PaymentStatus::Completed,
                'source' => PaymentSource::Gateway,
                'provider' => $locked->provider,
                'payment_date' => now(),
            ]);

            $collected = $this->applyCollected($statement);

            $receipt = $this->receipts->issue(
                $payment,
                $lockedRequest,
                $locked->revision,
                $balanceBefore,
                null,
                $locked->payer_name
            );

            $locked->fill([
                'status' => PaymentAttemptStatus::Completed,
                'completed_at' => now(),
                'provider_payment_id' => $providerPaymentId,
                'next_verification_at' => null,
                'review_required_at' => $reviewReason !== null ? now() : null,
                'review_reason' => $reviewReason,
            ])->save();

            $this->auditLogger->record(null, 'billing.payment_confirmed', $statement, [
                'payment_id' => $payment->id,
                'payment_attempt_id' => $locked->id,
                'amount_paid' => Money::toFloat($locked->amount_centavos),
                'collected' => Money::toFloat($collected),
                'status' => $statement->status->value,
                'receipt_number' => $receipt->receipt_number,
                'review_reason' => $reviewReason,
            ]);

            return $payment;
        });
    }

    /**
     * Apply an approved correction to one recorded payment.
     *
     * Only what was recorded can change — the amount, the method and the
     * reference — never the payment's status: a correction fixes a mistake, it
     * is not a way to turn money received into money refused. The statement is
     * then re-settled from what it has now collected, unless somebody decided
     * it (void, subsidised), which a payment never overrides.
     *
     * The payment's receipt, if it has one, is voided and replaced by a receipt
     * showing the corrected figures, so the original stays on record.
     *
     * A correction approved after the units were released can reopen the
     * statement. That is deliberate: the figure on the statement has to be true.
     * The release gate asks about payment at the moment of dispatch and not
     * again, so the reopened balance is surfaced as outstanding instead, and
     * the audit entry names the request's status at the time.
     *
     * Must be called inside a transaction that already holds the request, then
     * the billing row, then the payment — see lockForMutation(). Nothing here
     * locks.
     *
     * @param  array<string, mixed>  $changes  Already validated and normalised.
     */
    public function correctPayment(User $actor, BloodRequest $lockedRequest, Billing $lockedBilling, Payment $lockedPayment, array $changes): void
    {
        if ($lockedBilling->status === BillingStatus::Void) {
            throw $this->refuse(409, 'billing_void', 'This statement has been voided, so its payments can no longer be corrected.');
        }

        if ($lockedPayment->source === PaymentSource::Gateway) {
            throw $this->refuse(409, 'gateway_payment_not_correctable', 'A payment confirmed by the payment provider carries the provider\'s figures and cannot be corrected.');
        }

        // A correction carries the whole corrected payload, so what is audited
        // is the fields whose value actually differs, in canonical form.
        $types = CorrectionSubject::Payment->fieldTypes();
        $current = CorrectionValues::normalizeAll([
            'amount_paid' => $lockedPayment->amount_paid,
            'payment_method' => $lockedPayment->payment_method?->value,
            'reference_number' => $lockedPayment->reference_number,
        ], $types);

        $fields = [];

        foreach ($types as $field => $type) {
            if (array_key_exists($field, $changes) && ! CorrectionValues::same($current[$field] ?? null, $changes[$field], $type)) {
                $lockedPayment->{$field} = $changes[$field];
                $fields[] = $field;
            }
        }

        try {
            $lockedPayment->save();
        } catch (QueryException $exception) {
            // The validator checked the reference a moment ago; this is the
            // backstop for another payment taking it in between.
            if (in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'changes.reference_number' => ['That payment reference has already been recorded.'],
                ]);
            }

            throw $exception;
        }

        $statusFrom = $lockedBilling->status;

        $this->applyCollected($lockedBilling);

        $receipt = $fields === []
            ? null
            : $this->receipts->reissue($lockedPayment, $lockedRequest, $actor, 'Replaced after an approved correction to the payment.');

        $this->auditLogger->record($actor, 'billing.payment_corrected', $lockedBilling, [
            'request_id' => $lockedRequest->id,
            'payment_id' => $lockedPayment->id,
            'fields' => $fields,
            'status_from' => $statusFrom->value,
            'status_to' => $lockedBilling->status->value,
            'request_status' => $lockedRequest->status->value,
            'receipt_number' => $receipt?->receipt_number,
        ]);
    }

    /**
     * The payments recorded against a statement, as the one viewing them may act on each.
     *
     * Separate from format() on purpose. billing.view is held by roles that
     * only need to know whether a release is cleared, and a payment carries a
     * reference number they have no business reading; this is reached only
     * through billing.record_payment.
     *
     * can_request_correction and pending_correction describe the viewer and are
     * there to draw the screen. CorrectionService::request() enforces the same
     * rules on its own. A gateway payment is never correctable.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payments(Billing $billing, User $viewer): array
    {
        $payments = $billing->payments()
            ->with(['receipts' => fn ($query) => $query->whereNull('voided_at')])
            ->orderBy('payment_date')->orderBy('id')->get();

        $pending = CorrectionRequest::query()
            ->where('subject', CorrectionSubject::Payment->value)
            ->where('status', 'pending')
            ->whereIn('payment_id', $payments->pluck('id'))
            ->pluck('payment_id')
            ->all();

        $mayFile = $billing->status !== BillingStatus::Void
            && CorrectionSubject::Payment->mayBeFiledBy($viewer);

        return $payments->map(function (Payment $payment) use ($pending, $mayFile): array {
            $isPending = in_array($payment->id, $pending, true);
            $receipt = $payment->receipts->first();

            return [
                'id' => $payment->id,
                'amount_paid' => Money::toFloat(Money::toCentavos($payment->amount_paid)),
                'payment_method' => $payment->payment_method->value,
                'payment_method_label' => $payment->payment_method->label(),
                'reference_number' => $payment->reference_number,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'source' => $payment->source->value,
                'source_label' => $payment->source->label(),
                'payment_date' => $payment->payment_date?->toIso8601String(),
                'receipt' => $receipt ? $this->receipts->format($receipt) : null,
                'pending_correction' => $isPending,
                'can_request_correction' => $mayFile && ! $isPending && $payment->source === PaymentSource::Manual,
            ];
        })->all();
    }

    /**
     * Project a statement for the API.
     *
     * @return array<string, mixed>
     */
    public function format(Billing $billing): array
    {
        return [
            'id' => $billing->id,
            'request_id' => $billing->request_id,
            'total_amount' => Money::toFloat(Money::toCentavos($billing->total_amount)),
            'collected' => Money::toFloat($this->collectedFor($billing)),
            'status' => $billing->status->value,
            'status_label' => $billing->status->label(),
            'is_zero_rated' => $billing->isZeroRated(),
            'is_subsidised' => $billing->status === BillingStatus::Subsidised,
            'is_statement_only' => $billing->status === BillingStatus::StatementOnly,
            'collects_payment' => $billing->status->isCollectible(),
            // Distinguishes "nothing was owed" from "the money came in", which
            // a status alone cannot once a subsidy zeroes the total.
            'represents_collected_money' => $billing->status->representsCollectedMoney(),
            'clears_release' => $billing->clearsRelease(),
            'billing_date' => $billing->billing_date?->toIso8601String(),
        ];
    }

    /**
     * What is left to collect on a statement, in centavos. Zero for one that collects nothing.
     */
    public function outstandingFor(Billing $billing): int
    {
        if (! $billing->status->isCollectible()) {
            return 0;
        }

        return max(Money::toCentavos($billing->total_amount) - $this->collectedFor($billing), 0);
    }

    /**
     * Refuse a payment or checkout against a statement that takes none.
     *
     * A voided or subsidised statement was settled by a decision, and a
     * payment would have overturned it. A statement-only one is a weekly order
     * settled outside RedAgos. A statement with nothing outstanding — paid in
     * full, or raised at zero — has nothing to pay. Judged on the locked row,
     * like everything else a payment changes.
     */
    public function assertAcceptsPayment(Billing $lockedBilling): void
    {
        if ($lockedBilling->status === BillingStatus::Void) {
            throw $this->refuse(
                409,
                'billing_settled_by_decision',
                'This statement has been voided, so no payment can be recorded against it.'
            );
        }

        if ($lockedBilling->status === BillingStatus::Subsidised) {
            throw $this->refuse(
                409,
                'billing_settled_by_decision',
                'This statement is covered by the government subsidy, so no payment can be recorded against it.'
            );
        }

        if ($lockedBilling->status === BillingStatus::StatementOnly) {
            throw $this->refuse(
                409,
                'billing_not_collectible',
                'This weekly order is billed by statement only; no payment is recorded against it in RedAgos.'
            );
        }

        if ($this->outstandingFor($lockedBilling) <= 0) {
            throw $this->refuse(
                409,
                'billing_settled',
                'Nothing is outstanding on this statement.'
            );
        }
    }

    /**
     * Sum what a statement has actually collected, in centavos.
     */
    public function collectedFor(Billing $billing): int
    {
        return $this->figures->collectedFor($billing);
    }

    /**
     * Whether a request's statement collects payment in RedAgos at all.
     *
     * Only a Patient Transfusion does: its patient or watcher owes the bill. A
     * replenishment request is a weekly order, billed by statement only.
     */
    private function collectsPayment(BloodRequest $request): bool
    {
        return $request->request_purpose === RequestPurpose::PatientTransfusion;
    }

    /**
     * Refuse while a gateway checkout is open on the statement.
     *
     * Taking cash or waiving the charge while the payer may be paying online
     * would collect, or forgive, the same balance twice.
     */
    private function assertNoOpenCheckout(Billing $lockedBilling): void
    {
        $open = PaymentAttempt::query()
            ->where('billing_id', $lockedBilling->id)
            ->open()
            ->exists();

        if ($open) {
            throw $this->refuse(
                409,
                'payment_in_progress',
                'A GCash checkout is open on this statement. Wait for it to finish, or supersede it, before taking another payment.'
            );
        }
    }

    /**
     * Supersede the checkouts a changed statement would otherwise let a payer pay at the old figure.
     *
     * Only creating and active ones: an attempt awaiting verification may
     * already have collected money, so it keeps running to its own end.
     */
    private function supersedeOpenCheckouts(Billing $lockedBilling, string $reason): void
    {
        PaymentAttempt::query()
            ->where('billing_id', $lockedBilling->id)
            ->whereIn('status', [PaymentAttemptStatus::Creating, PaymentAttemptStatus::Active])
            ->update([
                'status' => PaymentAttemptStatus::Superseded->value,
                'failure_code' => $reason,
                'next_verification_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Re-settle a locked statement from what it has collected, unless its status is not money's to decide.
     *
     * Void and Subsidised are decisions, and StatementOnly is settled outside
     * RedAgos; no payment, correction or re-pricing changes those. Saves the
     * statement either way, so a caller that has just changed its total has
     * that change stored too.
     *
     * Must be called on a statement locked under lockForMutation()'s order.
     *
     * @return int What the statement has collected, in centavos.
     */
    private function applyCollected(Billing $lockedBilling): int
    {
        $collected = $this->collectedFor($lockedBilling);

        if ($lockedBilling->status->isCollectible()) {
            $lockedBilling->status = $this->settlementFor(
                Money::toCentavos($lockedBilling->total_amount),
                $collected
            );
        }

        $lockedBilling->save();

        return $collected;
    }

    /**
     * Decide a statement's settlement state from what it asks and what it has, both in centavos.
     *
     * A zero total is Paid rather than Unpaid: nothing is owed, so nothing is
     * outstanding, and treating it as unpaid would block every release under
     * the present subsidy.
     */
    private function settlementFor(int $total, int $collected): BillingStatus
    {
        if ($total <= 0 || $collected >= $total) {
            return BillingStatus::Paid;
        }

        return $collected > 0 ? BillingStatus::Partial : BillingStatus::Unpaid;
    }

    /**
     * Build the project's standard refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
