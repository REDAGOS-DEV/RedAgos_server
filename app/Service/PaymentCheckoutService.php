<?php

namespace App\Service;

use App\Enums\BillingStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\StaffRole;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Support\DocumentNumbering;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GCash checkouts opened by blood-centre billing staff for a patient's watcher.
 *
 * The watcher has no RedAgos account, so billing staff open the checkout at
 * the counter and hand over the link or its QR code (project owner,
 * 2026-10-10). The amount is never taken from the request: it is the amount
 * due on the statement revision frozen for this checkout, read under the
 * request and billing locks. Only the watcher's name, as staff typed it, goes
 * to Xendit — nothing from the patient record.
 *
 * At most one checkout per statement is open at a time. Opening one while
 * another is open returns that one; a statement that takes no payment, a
 * centre without a Xendit sub-account, or checkout switched off are refused.
 * Cash is unaffected by any of this, except that it waits while a checkout is
 * open.
 *
 * In a local test on a test key, XENDIT_ALLOW_MAIN_ACCOUNT lets a centre with
 * no sub-account collect into the master account instead (accountFor()).
 */
class PaymentCheckoutService
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly StatementRevisionService $statements,
        private readonly XenditGateway $gateway,
        private readonly PaymentEventProcessor $processor,
        private readonly AuditLogger $auditLogger,
        private readonly CashSessionService $cashSessions
    ) {}

    /**
     * Open a GCash checkout for one of this centre's statements, or return the one already open.
     *
     * @return array<string, mixed>
     */
    public function start(User $staff, int $requestId, string $payerName): array
    {
        if (! config('services.xendit.checkout_enabled')) {
            throw $this->refuse(503, 'checkout_disabled', 'GCash checkout is switched off. Take the payment in cash, or try again later.');
        }

        $facility = $this->facilityOf($staff);
        $account = $this->accountFor($facility);

        if ($account === null) {
            throw $this->refuse(409, 'merchant_not_configured', 'This blood centre has no GCash merchant account set up yet.');
        }

        [$attempt, $created] = DocumentNumbering::transaction(function () use ($staff, $requestId, $facility, $account, $payerName): array {
            [$request, $billing] = $this->billingService->lockForMutation($requestId, $facility->id);

            $open = PaymentAttempt::query()
                ->where('billing_id', $billing->id)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                return [$open, false];
            }

            $this->billingService->assertAcceptsPayment($billing);

            if ($this->billingService->outstandingFor($billing) > Money::toCentavos((string) config('services.xendit.max_amount'))) {
                throw $this->refuse(409, 'exceeds_channel_limit', 'The balance is above the GCash limit per payment. Take it in cash, or in parts.');
            }

            [$revision] = $this->statements->issueOrReuse($request, $billing, $staff, 'checkout');

            $attempt = PaymentAttempt::query()->create([
                'billing_id' => $billing->id,
                'billing_revision_id' => $revision->id,
                'initiated_by' => $staff->id,
                'initiator_facility_id' => $facility->id,
                // The counter shift it was opened in, if any, so the shift's
                // reading shows the GCash it took. GCash never touches the drawer.
                'cash_session_id' => $this->cashSessions->openShiftIdOf($staff),
                'provider' => 'xendit',
                'provider_account_id' => $account,
                'reference_id' => 'RA-'.Str::ulid(),
                'amount_centavos' => Money::toCentavos($revision->amount_due),
                'currency' => 'PHP',
                'payer_name' => $payerName,
                'status' => PaymentAttemptStatus::Creating,
            ]);

            return [$attempt, true];
        });

        if (! $created) {
            return [
                'message' => 'A GCash checkout is already open for this statement.',
                'created' => false,
                'attempt' => $this->format($attempt),
            ];
        }

        $attempt = $this->openSession($attempt);

        $this->auditLogger->record($staff, 'billing.checkout_started', $attempt->billing, [
            'payment_attempt_id' => $attempt->id,
            'amount' => Money::toFloat($attempt->amount_centavos),
            'revision_id' => $attempt->billing_revision_id,
        ]);

        return [
            'message' => 'GCash checkout opened. Show the QR code or send the link to the payer.',
            'created' => true,
            'attempt' => $this->format($attempt),
        ];
    }

    /**
     * Whether staff could open a checkout for this statement right now, and if not, why.
     *
     * Presentation only: start() checks every one of these again under lock.
     *
     * @return array{available: bool, reason: string|null}
     */
    public function availability(Billing $billing, ?Facility $facility): array
    {
        $reason = match (true) {
            ! config('services.xendit.checkout_enabled') => 'checkout_disabled',
            $this->accountFor($facility) === null => 'merchant_not_configured',
            $billing->status === BillingStatus::StatementOnly,
            $billing->status === BillingStatus::SettledOutside => 'billing_not_collectible',
            ! $billing->status->isCollectible() => 'billing_settled_by_decision',
            $this->billingService->outstandingFor($billing) <= 0 => 'billing_settled',
            PaymentAttempt::query()->where('billing_id', $billing->id)->open()->exists() => 'payment_in_progress',
            $this->billingService->outstandingFor($billing) > Money::toCentavos((string) config('services.xendit.max_amount')) => 'exceeds_channel_limit',
            default => null,
        };

        return ['available' => $reason === null, 'reason' => $reason];
    }

    /**
     * Every checkout opened against one of this centre's statements, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function attempts(User $staff, int $requestId): array
    {
        $billing = $this->billingFor($staff, $requestId);

        return PaymentAttempt::query()
            ->with('revision')
            ->where('billing_id', $billing->id)
            ->latest('id')
            ->get()
            ->map(fn (PaymentAttempt $attempt): array => $this->format($attempt))
            ->all();
    }

    /**
     * Close an open checkout before taking cash instead.
     *
     * Only one nobody has claimed to have paid. One awaiting verification may
     * already have collected money, so it has to be resolved first.
     *
     * @return array<string, mixed>
     */
    public function supersede(User $staff, int $requestId, int $attemptId, string $reason): array
    {
        $attempt = DB::transaction(function () use ($staff, $requestId, $attemptId): PaymentAttempt {
            [, $billing] = $this->billingService->lockForMutation($requestId, (int) $staff->facility_id);
            $attempt = $this->lockAttempt($billing, $attemptId);

            if ($attempt->status === PaymentAttemptStatus::AwaitingVerification) {
                throw $this->refuse(409, 'attempt_awaiting_verification', 'The provider may already have taken this payment. It is being confirmed and cannot be superseded.');
            }

            if (! $attempt->status->isOpen()) {
                throw $this->refuse(409, 'attempt_closed', 'This checkout is no longer open.');
            }

            // Still watched until its expiry, in case the payer pays the old link.
            $attempt->forceFill([
                'status' => PaymentAttemptStatus::Superseded,
                'failure_code' => 'superseded_by_staff',
                'next_verification_at' => now(),
            ])->save();

            return $attempt;
        });

        $this->auditLogger->record($staff, 'billing.checkout_superseded', $attempt->billing, [
            'payment_attempt_id' => $attempt->id,
            'reason' => $reason,
        ]);

        return ['message' => 'Checkout superseded.', 'attempt' => $this->format($attempt)];
    }

    /**
     * Ask Xendit again about a checkout, for a Billing Supervisor resolving one flagged for review.
     *
     * @return array<string, mixed>
     */
    public function reverify(User $staff, int $requestId, int $attemptId): array
    {
        $billing = $this->billingFor($staff, $requestId);
        $attempt = PaymentAttempt::query()->where('billing_id', $billing->id)->whereKey($attemptId)->first()
            ?? throw $this->refuse(404, 'attempt_not_found', 'Checkout not found.');

        $this->assertSupervisor($staff);

        $attempt->forceFill([
            'review_required_at' => null,
            'review_reason' => null,
            'verification_deadline_at' => null,
            'verification_attempts' => 0,
        ])->save();

        $attempt = $this->processor->verify($attempt, true);

        $this->auditLogger->record($staff, 'billing.checkout_reverified', $billing, [
            'payment_attempt_id' => $attempt->id,
            'status' => $attempt->status->value,
        ]);

        return ['message' => 'Checked with the payment provider.', 'attempt' => $this->format($attempt->refresh())];
    }

    /**
     * Close a checkout for good once Xendit confirms no payment was taken on it.
     *
     * For a Billing Supervisor. Xendit is asked first, and the close is
     * refused if it reports a payment, so nobody can close away money that
     * arrived.
     *
     * @return array<string, mixed>
     */
    public function close(User $staff, int $requestId, int $attemptId, string $reason): array
    {
        $billing = $this->billingFor($staff, $requestId);
        $found = PaymentAttempt::query()->where('billing_id', $billing->id)->whereKey($attemptId)->first()
            ?? throw $this->refuse(404, 'attempt_not_found', 'Checkout not found.');

        $this->assertSupervisor($staff);

        if ($found->status === PaymentAttemptStatus::Completed) {
            throw $this->refuse(409, 'attempt_closed', 'This checkout has already been paid.');
        }

        if ($found->provider_session_id !== null) {
            try {
                $session = $this->gateway->getSession($found->provider_account_id, $found->provider_session_id);
            } catch (RequestException|ConnectionException) {
                throw $this->refuse(502, 'gateway_unavailable', 'The payment provider could not be reached to confirm no payment was taken. Try again.');
            }

            if (strtoupper((string) ($session['status'] ?? '')) === 'COMPLETED' || ! empty($session['payment_id'])) {
                throw $this->refuse(409, 'provider_shows_payment', 'The payment provider reports a payment on this checkout. Re-check it instead of closing it.');
            }
        }

        $attempt = DB::transaction(function () use ($staff, $requestId, $attemptId): PaymentAttempt {
            [, $lockedBilling] = $this->billingService->lockForMutation($requestId, (int) $staff->facility_id);
            $attempt = $this->lockAttempt($lockedBilling, $attemptId);

            if ($attempt->status === PaymentAttemptStatus::Completed) {
                throw $this->refuse(409, 'attempt_closed', 'This checkout has already been paid.');
            }

            $attempt->forceFill([
                'status' => PaymentAttemptStatus::Canceled,
                'failure_code' => $attempt->failure_code ?? 'closed_by_supervisor',
                'next_verification_at' => null,
                'review_required_at' => null,
                'review_reason' => null,
            ])->save();

            return $attempt;
        });

        $this->auditLogger->record($staff, 'billing.checkout_closed', $billing, [
            'payment_attempt_id' => $attempt->id,
            'reason' => $reason,
        ]);

        return ['message' => 'Checkout closed.', 'attempt' => $this->format($attempt)];
    }

    /**
     * Project one attempt for the API. The payer's name is never included.
     *
     * @return array<string, mixed>
     */
    public function format(PaymentAttempt $attempt): array
    {
        $attempt->loadMissing('revision');

        return [
            'id' => $attempt->id,
            'status' => $attempt->status->value,
            'status_label' => $attempt->status->label(),
            'amount' => Money::toDecimal($attempt->amount_centavos),
            'currency' => $attempt->currency,
            // Only while it can still be paid; an old link must not be handed out.
            'checkout_url' => $attempt->status === PaymentAttemptStatus::Active ? $attempt->checkout_url : null,
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'statement_document_number' => $attempt->revision?->document_number,
            'failure_code' => $attempt->failure_code,
            'review_required' => $attempt->review_required_at !== null,
            'review_reason' => $attempt->review_reason,
            'created_at' => $attempt->created_at?->toIso8601String(),
            'completed_at' => $attempt->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Ask Xendit to open the hosted checkout for an attempt just created.
     *
     * Called after the creating transaction committed, so no lock is held
     * while waiting on the provider. A failure marks the attempt failed and is
     * refused to the caller; the statement is free for cash at once.
     */
    private function openSession(PaymentAttempt $attempt): PaymentAttempt
    {
        $ttl = (int) config('services.xendit.session_ttl_minutes', 30);

        try {
            $session = $this->gateway->createSession($attempt->provider_account_id, [
                'reference_id' => $attempt->reference_id,
                'session_type' => 'PAY',
                'mode' => 'PAYMENT_LINK',
                'amount' => Money::toFloat($attempt->amount_centavos),
                'currency' => $attempt->currency,
                'country' => 'PH',
                'allowed_payment_channels' => [(string) config('services.xendit.channel', 'GCASH')],
                'expires_at' => now()->addMinutes($ttl)->utc()->format('Y-m-d\TH:i:s\Z'),
                'success_return_url' => (string) config('services.xendit.return_url'),
                'cancel_return_url' => (string) config('services.xendit.return_url'),
                'customer' => [
                    'type' => 'INDIVIDUAL',
                    'reference_id' => $attempt->reference_id,
                    'individual_detail' => ['given_names' => $attempt->payer_name],
                ],
                'metadata' => ['payment_attempt_id' => (string) $attempt->id],
            ]);
        } catch (RequestException $exception) {
            $this->fail(
                $attempt,
                (string) ($exception->response->json('error_code') ?? 'gateway_error'),
                $exception->response->status(),
                (string) $exception->response->json('message'),
            );
        } catch (ConnectionException $exception) {
            $this->fail($attempt, 'gateway_unreachable', null, $exception->getMessage());
        }

        $sessionId = $session['payment_session_id'] ?? null;
        $checkoutUrl = $session['payment_link_url'] ?? null;

        if (! is_string($sessionId) || ! is_string($checkoutUrl)) {
            $this->fail($attempt, 'session_incomplete', null);
        }

        $attempt->forceFill([
            'status' => PaymentAttemptStatus::Active,
            'provider_session_id' => $sessionId,
            'provider_payment_request_id' => is_string($session['payment_request_id'] ?? null) ? $session['payment_request_id'] : null,
            'checkout_url' => $checkoutUrl,
            'expires_at' => isset($session['expires_at']) ? Carbon::parse((string) $session['expires_at']) : now()->addMinutes($ttl),
            'next_verification_at' => now()->addMinutes(5),
        ])->save();

        return $attempt;
    }

    /**
     * Mark an attempt that never opened as failed, and refuse the caller.
     *
     * The detail is Xendit's error message or the connection error, for the
     * operator. Neither carries the key, which travels only in a header.
     */
    private function fail(PaymentAttempt $attempt, string $code, ?int $httpStatus, string $detail = ''): never
    {
        $attempt->forceFill([
            'status' => PaymentAttemptStatus::Failed,
            'failure_code' => substr($code, 0, 60),
        ])->save();

        Log::warning('xendit.session_create_failed', [
            'payment_attempt_id' => $attempt->id,
            'http_status' => $httpStatus,
            'error_code' => $code,
            'detail' => substr($detail, 0, 300),
        ]);

        throw $this->refuse(502, 'gateway_unavailable', 'The payment provider could not open a checkout. Take the payment in cash, or try again.');
    }

    /**
     * The statement of one of this centre's requests, without locking it.
     */
    private function billingFor(User $staff, int $requestId): Billing
    {
        $request = BloodRequest::query()
            ->addressedTo((int) $staff->facility_id)
            ->whereKey($requestId)
            ->first()
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        return $request->billing()->first()
            ?? throw $this->refuse(404, 'billing_missing', 'No statement has been raised for this request yet.');
    }

    /**
     * Lock one of a locked statement's attempts.
     */
    private function lockAttempt(Billing $lockedBilling, int $attemptId): PaymentAttempt
    {
        return PaymentAttempt::query()
            ->where('billing_id', $lockedBilling->id)
            ->whereKey($attemptId)
            ->lockForUpdate()
            ->first()
            ?? throw $this->refuse(404, 'attempt_not_found', 'Checkout not found.');
    }

    /**
     * Refuse anyone but the Billing Supervisor or the Center Admin.
     *
     * Checked after the attempt is found, so a role that may not act learns
     * nothing it could not already see.
     */
    private function assertSupervisor(User $staff): void
    {
        if (! $staff->is_supervisor && $staff->staff_role !== StaffRole::BillingSupervisor) {
            throw $this->refuse(403, 'not_billing_supervisor', 'Only the Billing Supervisor or the Center Admin can resolve a checkout.');
        }
    }

    /**
     * The Xendit account a centre collects into, or null when it has none.
     *
     * Its own sub-account; failing that, the master account only while
     * XenditGateway::allowsMainAccount() says a local test permits it.
     */
    private function accountFor(?Facility $facility): ?string
    {
        if (filled($facility?->xendit_sub_account_id)) {
            return $facility->xendit_sub_account_id;
        }

        return $this->gateway->allowsMainAccount() ? XenditGateway::MAIN_ACCOUNT : null;
    }

    /**
     * The centre the caller acts for.
     */
    private function facilityOf(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
