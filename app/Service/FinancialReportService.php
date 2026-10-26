<?php

namespace App\Service;

use App\Enums\BillingStatus;
use App\Enums\PaymentMethod;
use App\Enums\RequestPurpose;
use App\Enums\TransactionType;
use App\Models\Billing;
use App\Models\BillingTransaction;
use App\Models\BloodComponent;
use App\Models\User;
use App\Support\Money;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * Financial Reports: the centre's money for a period, in the four parts the
 * project owner asked for (2026-10-25).
 *
 *  - Collections: what the counter and the gateway took, by method and over
 *    time, net of voids and corrections.
 *  - Subsidies: what the government subsidy waived, net of any removed.
 *  - Receivables: what patients and hospitals still owe today, by age, and
 *    the hospitals' settlements recorded in the period.
 *  - Billing by component and by hospital: the invoices raised in the period.
 *
 * Money moved comes from the billing journal, whose rows are never changed;
 * what is owed comes from the live bills. Read-only, scoped to the caller's
 * own centre, and added up in centavos on the server.
 */
class FinancialReportService
{
    /**
     * Up to this many days the collections are shown per day, beyond it per month.
     */
    private const DAILY_UP_TO = 62;

    public function __construct(
        private readonly StatementFigures $figures
    ) {}

    /**
     * The report for a period of operational (Manila) days.
     *
     * @return array<string, mixed>
     */
    public function build(User $staff, string $from, string $to): array
    {
        $centreId = $this->requireFacilityId($staff);
        [$start] = OperationalDay::boundsFor($from);
        [, $end] = OperationalDay::boundsFor($to);

        $rows = BillingTransaction::query()
            ->where('facility_id', $centreId)
            ->whereBetween('occurred_at', [$start, $end])
            ->get(['id', 'type', 'payment_method', 'amount', 'occurred_at']);

        $billed = Billing::query()
            ->whereHas('request', fn (Builder $request) => $request->addressedTo($centreId))
            ->whereBetween('billing_date', [$start, $end])
            ->where('status', '!=', BillingStatus::Void->value)
            ->with(['request.items.component', 'request.items.bloodType', 'request.requestingFacility:id,name'])
            ->get();

        $collections = $this->collections($rows, $from, $to);
        $subsidies = $this->subsidies($rows);
        $receivables = $this->receivables($centreId, $rows);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'summary' => [
                'invoices' => $billed->count(),
                'invoiced' => Money::toDecimal($this->sumOf($this->ofTypes($rows, TransactionType::charges()))),
                'collected' => $collections['total'],
                'subsidised' => $subsidies['amount'],
                'settled_by_hospitals' => $receivables['settlements']['amount'],
                'outstanding' => Money::toDecimal(
                    Money::toCentavos($receivables['patients']['amount']) + Money::toCentavos($receivables['hospitals']['amount'])
                ),
            ],
            'collections' => $collections,
            'subsidies' => $subsidies,
            'receivables' => $receivables,
            'by_component' => $this->byComponent($billed),
            'by_hospital' => $this->byHospital($billed),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Money taken, net of voids and corrections: in all, by method, and per day or month.
     *
     * @param  Collection<int, BillingTransaction>  $rows
     * @return array<string, mixed>
     */
    private function collections(Collection $rows, string $from, string $to): array
    {
        $moved = $this->ofTypes($rows, TransactionType::collections());
        $voids = $this->ofTypes($rows, [TransactionType::PaymentVoid]);

        $byMethod = array_map(function (PaymentMethod $method) use ($moved): array {
            $forMethod = $moved->filter(fn (BillingTransaction $row): bool => $row->payment_method === $method);

            return [
                'method' => $method->value,
                'label' => $method->label(),
                'amount' => Money::toDecimal(-$this->sumOf($forMethod)),
                'payments' => $forMethod->filter(fn (BillingTransaction $row): bool => $row->type === TransactionType::Payment)->count(),
            ];
        }, PaymentMethod::cases());

        return [
            'total' => Money::toDecimal(-$this->sumOf($moved)),
            'payments' => $this->ofTypes($rows, [TransactionType::Payment])->count(),
            'by_method' => $byMethod,
            'voids' => ['count' => $voids->count(), 'amount' => Money::toDecimal($this->sumOf($voids))],
            ...$this->series($moved, $from, $to),
        ];
    }

    /**
     * Collected per day for a short period, per month for a longer one, every bucket present.
     *
     * @param  Collection<int, BillingTransaction>  $moved
     * @return array{granularity: string, series: array<int, array{key: string, amount: string}>}
     */
    private function series(Collection $moved, string $from, string $to): array
    {
        $timezone = (string) config('blood_center.timezone');
        $first = CarbonImmutable::parse($from, $timezone);
        $last = CarbonImmutable::parse($to, $timezone);
        $daily = $first->diffInDays($last) + 1 <= self::DAILY_UP_TO;
        $format = $daily ? 'Y-m-d' : 'Y-m';

        $buckets = [];
        for ($cursor = $daily ? $first : $first->startOfMonth(); $cursor->lessThanOrEqualTo($last); $cursor = $daily ? $cursor->addDay() : $cursor->addMonth()) {
            $buckets[$cursor->format($format)] = 0;
        }

        foreach ($moved as $row) {
            $key = $row->occurred_at->setTimezone($timezone)->format($format);
            $buckets[$key] = ($buckets[$key] ?? 0) - Money::toCentavos($row->amount);
        }

        return [
            'granularity' => $daily ? 'day' : 'month',
            'series' => array_map(
                fn (string $key, int $amount): array => ['key' => $key, 'amount' => Money::toDecimal($amount)],
                array_keys($buckets),
                array_values($buckets)
            ),
        ];
    }

    /**
     * What the government subsidy met in the period, net of any subsidy removed.
     *
     * @param  Collection<int, BillingTransaction>  $rows
     * @return array{count: int, amount: string}
     */
    private function subsidies(Collection $rows): array
    {
        $applied = $this->ofTypes($rows, [TransactionType::Subsidy]);
        $removed = $this->ofTypes($rows, [TransactionType::SubsidyReversal]);

        return [
            'count' => max($applied->count() - $removed->count(), 0),
            'amount' => Money::toDecimal(-$this->sumOf($applied->concat($removed))),
        ];
    }

    /**
     * What is owed today, patients and hospitals apart and by age, and the settlements the period recorded.
     *
     * @param  Collection<int, BillingTransaction>  $rows
     * @return array<string, mixed>
     */
    private function receivables(int $centreId, Collection $rows): array
    {
        $patients = Billing::query()
            ->whereHas('request', fn (Builder $request) => $request->addressedTo($centreId)
                ->where('request_purpose', RequestPurpose::PatientTransfusion->value))
            ->outstanding()
            ->get(['id', 'request_id', 'billing_date', 'total_amount', 'status'])
            ->map(fn (Billing $billing): array => [
                'billed_on' => $billing->billing_date,
                'owed' => max(Money::toCentavos($billing->total_amount) - $this->figures->collectedFor($billing), 0),
            ]);

        $hospitals = Billing::query()
            ->whereHas('request', fn (Builder $request) => $request->addressedTo($centreId)
                ->where('request_purpose', RequestPurpose::Replenishment->value))
            ->where('status', BillingStatus::StatementOnly->value)
            ->get(['id', 'billing_date', 'total_amount'])
            ->map(fn (Billing $billing): array => [
                'billed_on' => $billing->billing_date,
                'owed' => Money::toCentavos($billing->total_amount),
            ]);

        $settlements = $this->ofTypes($rows, [TransactionType::ExternalSettlement]);

        return [
            'as_of' => now()->toIso8601String(),
            'patients' => $this->aged($patients),
            'hospitals' => $this->aged($hospitals),
            'settlements' => ['count' => $settlements->count(), 'amount' => Money::toDecimal(-$this->sumOf($settlements))],
        ];
    }

    /**
     * Open bills in all and by how long ago they were billed.
     *
     * @param  Collection<int, array{billed_on: CarbonImmutable|null, owed: int}>  $open
     * @return array<string, mixed>
     */
    private function aged(Collection $open): array
    {
        $today = OperationalDay::today()->startOfDay();
        $ages = [
            ['key' => 'week', 'label' => '0–7 days', 'max' => 7],
            ['key' => 'month', 'label' => '8–30 days', 'max' => 30],
            ['key' => 'older', 'label' => 'Over 30 days', 'max' => PHP_INT_MAX],
        ];

        $buckets = array_map(fn (array $age): array => ['key' => $age['key'], 'label' => $age['label'], 'count' => 0, 'amount' => 0], $ages);

        foreach ($open as $bill) {
            $days = $bill['billed_on'] ? (int) $bill['billed_on']->setTimezone($today->getTimezone())->startOfDay()->diffInDays($today) : 0;

            foreach ($ages as $i => $age) {
                if ($days <= $age['max']) {
                    $buckets[$i]['count']++;
                    $buckets[$i]['amount'] += $bill['owed'];
                    break;
                }
            }
        }

        return [
            'count' => $open->count(),
            'amount' => Money::toDecimal((int) $open->sum('owed')),
            'by_age' => array_map(fn (array $bucket): array => [...$bucket, 'amount' => Money::toDecimal($bucket['amount'])], $buckets),
        ];
    }

    /**
     * Units and amount billed per component, across the period's invoices, largest first.
     *
     * @param  Collection<int, Billing>  $billed
     * @return array<int, array<string, mixed>>
     */
    private function byComponent(Collection $billed): array
    {
        $totals = [];

        foreach ($billed as $billing) {
            if ($billing->request === null) {
                continue;
            }

            foreach ($this->figures->linesFor($billing->request) as $line) {
                $id = $line['component_id'];
                $totals[$id] ??= ['units' => 0, 'amount' => 0];
                $totals[$id]['units'] += $line['quantity'];
                $totals[$id]['amount'] += $line['line_total'];
            }
        }

        $names = BloodComponent::query()->whereIn('id', array_keys($totals))->pluck('name', 'id');

        return collect($totals)
            ->map(fn (array $total, int $id): array => [
                'component_id' => $id,
                'name' => $names[$id] ?? 'Component',
                'units' => $total['units'],
                'amount' => $total['amount'],
            ])
            ->sortByDesc('amount')
            ->values()
            ->map(fn (array $row): array => [...$row, 'amount' => Money::toDecimal($row['amount'])])
            ->all();
    }

    /**
     * The period's invoices per requesting hospital: how many, what they billed, and what is still owed on them.
     *
     * @param  Collection<int, Billing>  $billed
     * @return array<int, array<string, mixed>>
     */
    private function byHospital(Collection $billed): array
    {
        return $billed
            ->groupBy(fn (Billing $billing): int => (int) $billing->request?->facility_id)
            ->map(function (Collection $bills, int $facilityId): array {
                $outstanding = (int) $bills->sum(fn (Billing $billing): int => match (true) {
                    $billing->status === BillingStatus::StatementOnly => Money::toCentavos($billing->total_amount),
                    in_array($billing->status, [BillingStatus::Unpaid, BillingStatus::Partial], true) => max(Money::toCentavos($billing->total_amount) - $this->figures->collectedFor($billing), 0),
                    default => 0,
                });
                $billedAmount = (int) $bills->sum(fn (Billing $billing): int => Money::toCentavos($billing->total_amount));
                $weekly = $bills->filter(fn (Billing $billing): bool => $billing->request?->request_purpose === RequestPurpose::Replenishment)->count();

                return [
                    'facility_id' => $facilityId,
                    'name' => $bills->first()->request?->requestingFacility?->name ?? 'Unknown facility',
                    'invoices' => $bills->count(),
                    'patient_invoices' => $bills->count() - $weekly,
                    'weekly_invoices' => $weekly,
                    'billed' => $billedAmount,
                    'outstanding' => $outstanding,
                ];
            })
            ->sortByDesc('billed')
            ->values()
            ->map(fn (array $row): array => [...$row, 'billed' => Money::toDecimal($row['billed']), 'outstanding' => Money::toDecimal($row['outstanding'])])
            ->all();
    }

    /**
     * @param  Collection<int, BillingTransaction>  $rows
     * @param  array<int, TransactionType>  $types
     * @return Collection<int, BillingTransaction>
     */
    private function ofTypes(Collection $rows, array $types): Collection
    {
        return $rows->filter(fn (BillingTransaction $row): bool => in_array($row->type, $types, true))->values();
    }

    /**
     * @param  Collection<int, BillingTransaction>  $rows
     */
    private function sumOf(Collection $rows): int
    {
        return (int) $rows->sum(fn (BillingTransaction $row): int => Money::toCentavos($row->amount));
    }

    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw new HttpResponseException(response()->json([
            'message' => 'This account is not linked to a facility.',
            'code' => 'facility_missing',
        ], 404));
    }
}
