<?php

namespace App\Service;

use App\Enums\BillingStatus;
use App\Enums\RequestPurpose;
use App\Models\Billing;
use App\Models\User;
use App\Support\Money;
use App\Support\OperationalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The Billing department's dashboard (project owner, 2026-10-27): today at
 * the counter, what is still owed and how old it is, the last 30 days of
 * collections, and the hospitals that owe the most.
 *
 * Built from the same figures as the billing summary and Financial Reports,
 * so the three never disagree. For whoever works the counter
 * (billing.record_payment); the full report with its export stays the
 * Billing Supervisor's (reports.financial).
 */
class BillingDashboardService
{
    private const TREND_DAYS = 30;

    private const TOP_HOSPITALS = 5;

    public function __construct(
        private readonly BillingTransactionService $billingTransactionService,
        private readonly FinancialReportService $financialReportService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $staff): array
    {
        $centreId = $staff->facility_id ?? throw new HttpResponseException(response()->json([
            'message' => 'This account is not linked to a facility.',
            'code' => 'facility_missing',
        ], 404));

        $today = OperationalDay::today();
        $period = $this->financialReportService->build(
            $staff,
            $today->subDays(self::TREND_DAYS - 1)->toDateString(),
            $today->toDateString()
        );

        return [
            'today' => $this->billingTransactionService->summary($staff),
            'collections' => $period['collections'],
            'receivables' => $period['receivables'],
            'top_hospitals' => $this->topHospitals($centreId),
            'as_of' => now()->toIso8601String(),
        ];
    }

    /**
     * The hospitals with the most still to settle on their weekly invoices.
     *
     * @return array<int, array{facility_id: int|null, name: string, invoices: int, amount: string}>
     */
    private function topHospitals(int $centreId): array
    {
        return Billing::query()
            ->whereHas('request', fn (Builder $request) => $request->addressedTo($centreId)
                ->where('request_purpose', RequestPurpose::Replenishment->value))
            ->where('status', BillingStatus::StatementOnly->value)
            ->with('request.requestingFacility:id,name')
            ->get(['id', 'request_id', 'total_amount'])
            ->groupBy(fn (Billing $billing) => $billing->request?->facility_id)
            ->map(fn ($bills, $facilityId): array => [
                'facility_id' => $facilityId ? (int) $facilityId : null,
                'name' => $bills->first()->request?->requestingFacility?->name ?? 'Unknown hospital',
                'invoices' => $bills->count(),
                'centavos' => (int) $bills->sum(fn (Billing $billing): int => Money::toCentavos($billing->total_amount)),
            ])
            ->sortByDesc('centavos')
            ->take(self::TOP_HOSPITALS)
            ->map(fn (array $row): array => [
                'facility_id' => $row['facility_id'],
                'name' => $row['name'],
                'invoices' => $row['invoices'],
                'amount' => Money::toDecimal($row['centavos']),
            ])
            ->values()
            ->all();
    }
}
