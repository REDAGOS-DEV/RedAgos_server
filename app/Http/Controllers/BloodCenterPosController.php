<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseCashShiftRequest;
use App\Http\Requests\ListCashShiftsRequest;
use App\Http\Requests\OpenCashShiftRequest;
use App\Service\CashSessionService;
use App\Service\PosService;
use App\Support\Money;
use App\Support\OperationalDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The billing counter: cash shifts, and finding the bill to take payment on.
 *
 * The payment itself is POST /billings/{request}/payments, as from the
 * statements page; it needs the shift opened here.
 */
class BloodCenterPosController extends Controller
{
    public function __construct(
        private readonly CashSessionService $shifts,
        private readonly PosService $pos
    ) {}

    /**
     * The caller's open shift with its running figures, or null, and whether the counter uses shifts at all.
     */
    public function current(Request $request): JsonResponse
    {
        return response()->json([
            'shifts_enabled' => $this->shifts->enabled(),
            'session' => $this->shifts->current($request->user()),
        ]);
    }

    public function open(OpenCashShiftRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json($this->shifts->open(
            $request->user(),
            Money::toCentavos($validated['opening_float']),
            $validated['counter_label'] ?? null
        ), 201);
    }

    public function close(CloseCashShiftRequest $request, int $session): JsonResponse
    {
        $validated = $request->validated();

        return response()->json($this->shifts->close(
            $request->user(),
            $session,
            Money::toCentavos($validated['counted_cash']),
            $validated['count_breakdown'] ?? null,
            $validated['closing_note'] ?? null
        ));
    }

    public function index(ListCashShiftsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        // Operational (Manila) days, compared as instants.
        if (isset($filters['from'])) {
            $filters['from_instant'] = OperationalDay::boundsFor($filters['from'])[0];
        }

        if (isset($filters['to'])) {
            $filters['to_instant'] = OperationalDay::boundsFor($filters['to'])[1];
        }

        return response()->json([
            ...$this->shifts->list($request->user(), $filters, (int) ($filters['per_page'] ?? 15))->toArray(),
            'may_oversee' => $this->shifts->mayOversee($request->user()),
            'shifts_enabled' => $this->shifts->enabled(),
        ]);
    }

    public function show(Request $request, int $session): JsonResponse
    {
        return response()->json(['session' => $this->shifts->show($request->user(), $session)]);
    }

    public function pdf(Request $request, int $session): Response
    {
        return $this->shifts->pdf($request->user(), $session);
    }

    /**
     * Find a patient bill by reference or name.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']]);

        return response()->json(['data' => $this->pos->lookup($request->user(), $validated['q'])]);
    }

    /**
     * The patient bills holding blood back.
     */
    public function queue(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->pos->queue($request->user())]);
    }

    public function bill(Request $request, int $bloodRequest): JsonResponse
    {
        return response()->json(['bill' => $this->pos->bill($request->user(), $bloodRequest)]);
    }
}
