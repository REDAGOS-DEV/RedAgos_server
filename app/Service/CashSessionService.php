<?php

namespace App\Service;

use App\Enums\CorrectionSubject;
use App\Enums\PaymentMethod;
use App\Enums\StaffRole;
use App\Enums\TransactionChannel;
use App\Enums\TransactionType;
use App\Models\BillingTransaction;
use App\Models\CashSession;
use App\Models\CorrectionRequest;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Repository\DocumentSequenceRepository;
use App\Support\DocumentNumbering;
use App\Support\FacilityMonogram;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash shifts at the billing counter: open with a float, take payments, close with a count.
 *
 * Switched by `blood_center.cash_shifts` (BILLING_CASH_SHIFTS), off by owner
 * decision while one billing staff member runs the counter. Off, no shift is
 * opened, payments carry none, and a void's window is the day the payment was
 * taken (BillingService::voidWindowIsOpen()). On, everything below applies.
 *
 * A cashier takes no payment at the counter without an open shift
 * (BillingService::recordPayment()). What the drawer should hold at close is
 * worked out from the shift's own journal rows — the float, plus cash taken,
 * less cash voided — and frozen on the shift beside what was counted, with
 * the difference. A difference must be explained.
 *
 * A shift is the cashier's own. The Billing Supervisor, or the centre's
 * supervisor, sees every shift at the centre and may close one a cashier left
 * open; nobody else may.
 *
 * Locking. A payment locks the request, the statement and then the shift; a
 * close locks only the shift. Neither waits on the other the wrong way round.
 */
class CashSessionService
{
    /**
     * The notes and coins a drawer count may list, as pesos.
     *
     * @var array<int, string>
     */
    public const DENOMINATIONS = ['1000', '500', '200', '100', '50', '20', '10', '5', '1', '0.25'];

    public function __construct(
        private readonly DocumentSequenceRepository $sequences,
        private readonly BillingTransactionService $transactions,
        private readonly FacilityLogoService $facilityLogoService,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Whether the counter works in cash shifts at all.
     */
    public function enabled(): bool
    {
        return (bool) config('blood_center.cash_shifts');
    }

    /**
     * Open a shift for the caller, with the cash put in the drawer.
     *
     * @return array<string, mixed>
     */
    public function open(User $cashier, int $openingFloat, ?string $counterLabel): array
    {
        if (! $this->enabled()) {
            throw $this->refuse(409, 'cash_shifts_disabled', 'The counter does not use cash shifts. Payments are taken without one.');
        }

        $facilityId = $this->requireFacilityId($cashier);

        try {
            $session = DocumentNumbering::transaction(function () use ($cashier, $facilityId, $openingFloat, $counterLabel): CashSession {
                if ($this->openShiftQuery($cashier)->lockForUpdate()->exists()) {
                    throw $this->refuse(409, 'shift_already_open', 'You already have a shift open. Close it before opening another.');
                }

                return CashSession::query()->create([
                    'session_number' => DocumentNumbering::format('CS', $facilityId, $this->sequences->next($facilityId, 'cash_session')),
                    'facility_id' => $facilityId,
                    'cashier_id' => $cashier->id,
                    'counter_label' => $counterLabel,
                    'status' => CashSession::STATUS_OPEN,
                    'opening_float' => Money::toDecimal($openingFloat),
                    'opened_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            // The partial unique index stands behind the check above.
            if (in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw $this->refuse(409, 'shift_already_open', 'You already have a shift open. Close it before opening another.');
            }

            throw $exception;
        }

        $this->auditLogger->record($cashier, 'billing.shift_opened', $session, [
            'session_number' => $session->session_number,
            'opening_float' => Money::toFloat($openingFloat),
            'counter_label' => $counterLabel,
        ]);

        return [
            'message' => "Shift {$session->session_number} opened.",
            'session' => $this->report($session),
        ];
    }

    /**
     * The caller's open shift with its running figures, or null.
     *
     * @return array<string, mixed>|null
     */
    public function current(User $cashier): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $session = $this->openShiftQuery($cashier)->first();

        return $session ? $this->report($session) : null;
    }

    /**
     * The caller's open shift, locked for a payment. Refused when there is none.
     *
     * Must be called inside the payment's transaction, after the request and
     * statement locks.
     */
    public function lockOpenShiftOf(User $cashier): CashSession
    {
        return $this->openShiftQuery($cashier)->lockForUpdate()->first()
            ?? throw $this->refuse(
                409,
                'no_open_cash_session',
                'Open a shift at the counter before taking a payment.'
            );
    }

    /**
     * The caller's open shift id, if any. For a GCash checkout, which belongs to the shift that opened it.
     */
    public function openShiftIdOf(User $cashier): ?int
    {
        if (! $this->enabled()) {
            return null;
        }

        $id = $this->openShiftQuery($cashier)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Close a shift with the drawer counted.
     *
     * The caller's own, or — for the Billing Supervisor or the centre's
     * supervisor — any shift at the centre a cashier left open. Refused while
     * a GCash checkout opened in the shift is still open, or a void of one of
     * its payments is still awaiting a decision: either could still change
     * what the drawer should hold.
     *
     * @param  array<string, int>|null  $breakdown  Denomination => how many.
     * @return array<string, mixed>
     */
    public function close(User $staff, int $sessionId, int $counted, ?array $breakdown, ?string $note): array
    {
        $facilityId = $this->requireFacilityId($staff);

        $session = DB::transaction(function () use ($staff, $facilityId, $sessionId, $counted, $breakdown, $note): CashSession {
            $session = CashSession::query()
                ->whereKey($sessionId)
                ->where('facility_id', $facilityId)
                ->lockForUpdate()
                ->first()
                ?? throw $this->refuse(404, 'shift_not_found', 'Shift not found.');

            if ((int) $session->cashier_id !== (int) $staff->id && ! $this->mayOversee($staff)) {
                throw $this->refuse(403, 'shift_not_yours', 'Only the cashier, or a billing supervisor, may close this shift.');
            }

            if (! $session->isOpen()) {
                throw $this->refuse(409, 'shift_closed', 'This shift is already closed.');
            }

            if (PaymentAttempt::query()->where('cash_session_id', $session->id)->open()->exists()) {
                throw $this->refuse(
                    409,
                    'checkout_open_on_shift',
                    'A GCash checkout opened in this shift is still open. Wait for it to finish, or supersede it, before closing.'
                );
            }

            if ($this->pendingVoids($session)->isNotEmpty()) {
                throw $this->refuse(
                    409,
                    'void_pending_on_shift',
                    'A void of a payment taken in this shift is awaiting a decision. Have it approved or rejected before closing.'
                );
            }

            if ($breakdown !== null && $this->breakdownTotal($breakdown) !== $counted) {
                throw ValidationException::withMessages([
                    'counted_cash' => ['The counted cash does not match the notes and coins listed.'],
                ]);
            }

            $expected = $this->figures($session)['expected_cash'];
            $variance = $counted - $expected;

            if ($variance !== 0 && trim((string) $note) === '') {
                throw ValidationException::withMessages([
                    'closing_note' => ['The drawer is '.($variance > 0 ? 'over' : 'short').' by '.Money::toDecimal(abs($variance)).'. Say why before closing.'],
                ]);
            }

            $session->fill([
                'status' => CashSession::STATUS_CLOSED,
                'expected_cash' => Money::toDecimal($expected),
                'counted_cash' => Money::toDecimal($counted),
                'variance' => Money::toDecimal($variance),
                'count_breakdown' => $breakdown,
                'closing_note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
                'closed_at' => now(),
                'closed_by' => $staff->id,
            ])->save();

            $this->auditLogger->record($staff, 'billing.shift_closed', $session, [
                'session_number' => $session->session_number,
                'expected_cash' => Money::toFloat($expected),
                'counted_cash' => Money::toFloat($counted),
                'variance' => Money::toFloat($variance),
                'closed_for_cashier' => (int) $session->cashier_id !== (int) $staff->id ? $session->cashier_id : null,
            ]);

            return $session;
        });

        $variance = Money::toCentavos($session->variance);

        return [
            'message' => match (true) {
                $variance === 0 => "Shift {$session->session_number} closed. The drawer balances.",
                $variance > 0 => "Shift {$session->session_number} closed, over by ".Money::toDecimal($variance).'.',
                default => "Shift {$session->session_number} closed, short by ".Money::toDecimal(-$variance).'.',
            },
            'session' => $this->report($session->fresh()),
        ];
    }

    /**
     * The shifts the caller may see, newest first: their own, or every one at the centre for an overseer.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $staff, array $filters, int $perPage): LengthAwarePaginator
    {
        $facilityId = $this->requireFacilityId($staff);

        return CashSession::query()
            ->with(['cashier:id,first_name,last_name', 'closer:id,first_name,last_name'])
            ->where('facility_id', $facilityId)
            ->when(! $this->mayOversee($staff), fn ($query) => $query->where('cashier_id', $staff->id))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['cashier_id']), fn ($query) => $query->where('cashier_id', $filters['cashier_id']))
            ->when(isset($filters['from']), fn ($query) => $query->where('opened_at', '>=', $filters['from_instant']))
            ->when(isset($filters['to']), fn ($query) => $query->where('opened_at', '<=', $filters['to_instant']))
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (CashSession $session): array => $this->format($session));
    }

    /**
     * One shift the caller may see, with its reading.
     *
     * @return array<string, mixed>
     */
    public function show(User $staff, int $sessionId): array
    {
        return $this->report($this->findVisible($staff, $sessionId));
    }

    /**
     * Print a shift's reading — an X reading while it is open, the Z reading once closed.
     */
    public function pdf(User $staff, int $sessionId): Response
    {
        $session = $this->findVisible($staff, $sessionId);
        $report = $this->report($session);
        $session->loadMissing('facility');

        $this->auditLogger->record($staff, 'billing.shift_report_downloaded', $session, [
            'session_number' => $session->session_number,
        ]);

        // dompdf cannot decode a PNG without GD; the report still prints without the logo.
        $images = extension_loaded('gd');

        return Pdf::loadView('pdf.cash-shift-report', [
            'report' => $report,
            'facility' => $session->facility,
            'logo' => $images ? $this->facilityLogoService->dataUriFor($session->facility) : null,
            'monogram' => FacilityMonogram::of($session->facility?->name),
        ])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->download("{$session->session_number}.pdf");
    }

    /**
     * A shift with its reading: the money in and out of the drawer, and every transaction posted in it.
     *
     * @return array<string, mixed>
     */
    public function report(CashSession $session): array
    {
        $rows = $this->rowsOf($session);
        $figures = $this->figures($session, $rows);

        return [
            ...$this->format($session),
            'figures' => array_map(
                fn (int $centavos): string => Money::toDecimal($centavos),
                $figures
            ),
            'counts' => [
                'payments' => $rows->filter(fn (BillingTransaction $row): bool => $row->type === TransactionType::Payment)->count(),
                'voids' => $rows->filter(fn (BillingTransaction $row): bool => $row->type === TransactionType::PaymentVoid)->count(),
            ],
            'pending_voids' => $session->isOpen() ? $this->pendingVoids($session)->count() : 0,
            'transactions' => $rows->map(fn (BillingTransaction $row): array => $this->transactions->format($row))->values()->all(),
        ];
    }

    /**
     * Project a shift without its reading.
     *
     * @return array<string, mixed>
     */
    public function format(CashSession $session): array
    {
        $session->loadMissing(['cashier:id,first_name,last_name', 'closer:id,first_name,last_name']);

        return [
            'id' => $session->id,
            'session_number' => $session->session_number,
            'status' => $session->status,
            'status_label' => $session->isOpen() ? 'Open' : 'Closed',
            'counter_label' => $session->counter_label,
            'cashier' => $session->cashier ? [
                'id' => $session->cashier->id,
                'name' => trim($session->cashier->first_name.' '.$session->cashier->last_name),
            ] : null,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'closed_by' => $session->closer ? trim($session->closer->first_name.' '.$session->closer->last_name) : null,
            'opening_float' => $session->opening_float,
            'expected_cash' => $session->expected_cash,
            'counted_cash' => $session->counted_cash,
            'variance' => $session->variance,
            'count_breakdown' => $session->count_breakdown,
            'closing_note' => $session->closing_note,
        ];
    }

    /**
     * Whether a user oversees every cashier's shifts: the Billing Supervisor, or the centre's supervisor.
     */
    public function mayOversee(User $staff): bool
    {
        return (bool) $staff->is_supervisor || $staff->staff_role === StaffRole::BillingSupervisor;
    }

    /**
     * The drawer's figures, in centavos, worked out from the shift's journal rows.
     *
     * Cash rows count both ways: a payment and an increase on correction put
     * cash in, a void or a decrease takes it out. GCash never touches the
     * drawer and is shown beside it, split by how it came in.
     *
     * @param  Collection<int, BillingTransaction>|null  $rows
     * @return array<string, int>
     */
    public function figures(CashSession $session, ?Collection $rows = null): array
    {
        $rows ??= $this->rowsOf($session);

        $collections = array_map(fn (TransactionType $type): string => $type->value, TransactionType::collections());
        $sum = fn (callable $filter): int => -(int) $rows
            ->filter(fn (BillingTransaction $row): bool => in_array($row->type->value, $collections, true) && $filter($row))
            ->sum(fn (BillingTransaction $row): int => Money::toCentavos($row->amount));

        $cash = fn (BillingTransaction $row): bool => $row->payment_method === PaymentMethod::Cash;

        $cashIn = $sum(fn (BillingTransaction $row): bool => $cash($row) && $row->type !== TransactionType::PaymentVoid);
        $cashVoided = -$sum(fn (BillingTransaction $row): bool => $cash($row) && $row->type === TransactionType::PaymentVoid);
        $float = Money::toCentavos($session->opening_float);
        $expected = $float + $cashIn - $cashVoided;

        $figures = [
            'opening_float' => $float,
            'cash_collected' => $cashIn,
            'cash_voided' => $cashVoided,
            'expected_cash' => $expected,
            'gcash_counter' => $sum(fn (BillingTransaction $row): bool => $row->payment_method === PaymentMethod::Gcash && $row->channel === TransactionChannel::Counter),
            'gcash_checkout' => $sum(fn (BillingTransaction $row): bool => $row->channel === TransactionChannel::Gateway),
        ];

        $figures['total_collected'] = $figures['cash_collected'] - $figures['cash_voided'] + $figures['gcash_counter'] + $figures['gcash_checkout'];

        if (! $session->isOpen()) {
            $figures['expected_cash'] = Money::toCentavos($session->expected_cash);
            $figures['counted_cash'] = Money::toCentavos($session->counted_cash);
            $figures['variance'] = Money::toCentavos($session->variance);
        }

        return $figures;
    }

    /**
     * Add up a drawer count, in centavos.
     *
     * @param  array<string, int>  $breakdown
     */
    public function breakdownTotal(array $breakdown): int
    {
        $total = 0;

        foreach ($breakdown as $denomination => $count) {
            $total += Money::toCentavos((string) $denomination) * (int) $count;
        }

        return $total;
    }

    /**
     * @return Collection<int, BillingTransaction>
     */
    private function rowsOf(CashSession $session): Collection
    {
        return BillingTransaction::query()
            ->with(BillingTransactionService::FORMAT_RELATIONS)
            ->where('cash_session_id', $session->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Void requests on this shift's payments still awaiting a decision.
     *
     * @return Collection<int, CorrectionRequest>
     */
    private function pendingVoids(CashSession $session): Collection
    {
        return CorrectionRequest::query()
            ->where('subject', CorrectionSubject::PaymentVoid->value)
            ->where('status', 'pending')
            ->whereIn('payment_id', fn ($query) => $query->select('id')->from('payments')->where('cash_session_id', $session->id))
            ->get();
    }

    private function findVisible(User $staff, int $sessionId): CashSession
    {
        $session = CashSession::query()
            ->whereKey($sessionId)
            ->where('facility_id', $this->requireFacilityId($staff))
            ->first();

        if ($session === null || ((int) $session->cashier_id !== (int) $staff->id && ! $this->mayOversee($staff))) {
            throw $this->refuse(404, 'shift_not_found', 'Shift not found.');
        }

        return $session;
    }

    private function openShiftQuery(User $cashier)
    {
        return CashSession::query()
            ->where('cashier_id', $cashier->id)
            ->where('facility_id', (int) $cashier->facility_id)
            ->open();
    }

    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
