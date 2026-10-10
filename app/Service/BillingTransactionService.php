<?php

namespace App\Service;

use App\Enums\BillingStatus;
use App\Enums\RequestPurpose;
use App\Enums\TransactionType;
use App\Models\Billing;
use App\Models\BillingTransaction;
use App\Models\User;
use App\Support\Money;
use App\Support\OperationalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reading the billing journal: the Transactions list, its export, and the billing summary.
 *
 * Read-only. Rows are written by BillingLedger alone. Everything is scoped to
 * the caller's own centre, and every figure is added up in centavos on the
 * server — never from whatever page a browser happens to have loaded.
 */
class BillingTransactionService
{
    /**
     * The relations format() reads, for any query that projects rows.
     *
     * @var array<int, string>
     */
    public const FORMAT_RELATIONS = [
        'request:id,reference_number',
        'receipt:id,receipt_number',
        'revision:id,document_number',
        'cashSession:id,session_number',
        'recorder:id,first_name,last_name',
        'reverses:id,transaction_number',
    ];

    public function __construct(
        private readonly StatementFigures $figures,
        private readonly FacilityLogoService $facilityLogoService
    ) {}

    /**
     * List the centre's journal rows, newest first, with the totals of everything the filters match.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(User $staff, array $filters, int $perPage): array
    {
        $query = $this->filtered($this->requireFacilityId($staff), $filters);

        $page = (clone $query)
            ->with(self::FORMAT_RELATIONS)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (BillingTransaction $row): array => $this->format($row));

        return [
            ...$page->toArray(),
            'totals' => $this->totalsOf($query),
        ];
    }

    /**
     * Stream the rows the filters match as CSV, oldest first, for the centre's own books.
     *
     * @param  array<string, mixed>  $filters
     */
    public function export(User $staff, array $filters): StreamedResponse
    {
        $query = $this->filtered($this->requireFacilityId($staff), $filters)
            ->with(self::FORMAT_RELATIONS)
            ->orderBy('occurred_at')
            ->orderBy('id');

        $filename = 'billing-transactions-'.OperationalDay::todayAsDate().'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Transaction', 'Date', 'Category', 'Type', 'Channel', 'Method', 'Amount', 'Balance after',
                'Request', 'Receipt', 'Statement', 'Shift', 'Reference', 'Recorded by', 'Note',
            ]);

            $query->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    $line = $this->format($row);

                    fputcsv($out, [
                        $line['transaction_number'],
                        $row->occurred_at?->timezone(config('blood_center.timezone'))->format('Y-m-d H:i'),
                        $line['category_label'],
                        $line['type_label'],
                        $line['channel_label'],
                        $line['payment_method_label'],
                        $line['amount'],
                        $line['balance_after'],
                        $line['request']['reference_number'] ?? '',
                        $line['receipt']['receipt_number'] ?? '',
                        $line['statement']['document_number'] ?? '',
                        $line['cash_session']['session_number'] ?? '',
                        $line['reference'],
                        $line['recorded_by'],
                        $line['note'],
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The centre's billing at a glance, counted on the server.
     *
     * Patient bills still blocking release and what they owe; what the
     * counter and the gateway collected today and this month, less voids;
     * what the subsidy waived this month; and the weekly bills still awaiting
     * the hospital's settlement.
     *
     * @return array<string, mixed>
     */
    public function summary(User $staff): array
    {
        $facilityId = $this->requireFacilityId($staff);
        $staff->loadMissing('facility');

        $outstanding = Billing::query()
            ->whereHas('request', fn (Builder $request) => $request->addressedTo($facilityId))
            ->outstanding()
            ->get();

        $owed = $outstanding->sum(
            fn (Billing $billing): int => max(Money::toCentavos($billing->total_amount) - $this->figures->collectedFor($billing), 0)
        );

        $weekly = Billing::query()
            ->whereHas('request', fn (Builder $request) => $request->addressedTo($facilityId)->where('request_purpose', RequestPurpose::Replenishment->value))
            ->whereIn('status', [BillingStatus::StatementOnly->value, BillingStatus::SettledOutside->value])
            ->get(['id', 'status', 'total_amount', 'settled_at']);

        $awaiting = $weekly->filter(fn (Billing $billing): bool => $billing->status === BillingStatus::StatementOnly);

        [$todayStart, $todayEnd] = OperationalDay::boundsFor();
        $monthStart = OperationalDay::today()->startOfMonth()->setTimezone((string) config('app.timezone', 'UTC'));

        $collections = array_map(fn (TransactionType $type): string => $type->value, TransactionType::collections());

        $collected = fn ($from, $to): int => -$this->sumOf(
            BillingTransaction::query()
                ->where('facility_id', $facilityId)
                ->whereIn('type', $collections)
                ->whereBetween('occurred_at', [$from, $to])
        );

        $now = now();

        return [
            'outstanding' => [
                'count' => $outstanding->count(),
                'amount' => Money::toDecimal($owed),
            ],
            'collected_today' => Money::toDecimal($collected($todayStart, $todayEnd)),
            'collected_this_month' => Money::toDecimal($collected($monthStart, $now)),
            'subsidised_this_month' => [
                'count' => BillingTransaction::query()
                    ->where('facility_id', $facilityId)
                    ->where('type', TransactionType::Subsidy->value)
                    ->whereBetween('occurred_at', [$monthStart, $now])
                    ->count(),
                'amount' => Money::toDecimal(-$this->sumOf(
                    BillingTransaction::query()
                        ->where('facility_id', $facilityId)
                        ->where('type', TransactionType::Subsidy->value)
                        ->whereBetween('occurred_at', [$monthStart, $now])
                )),
            ],
            'weekly_awaiting_settlement' => [
                'count' => $awaiting->count(),
                'amount' => Money::toDecimal((int) $awaiting->sum(fn (Billing $billing): int => Money::toCentavos($billing->total_amount))),
            ],
            'weekly_settled_this_month' => $weekly
                ->filter(fn (Billing $billing): bool => $billing->status === BillingStatus::SettledOutside
                    && $billing->settled_at !== null
                    && $billing->settled_at->greaterThanOrEqualTo($monthStart))
                ->count(),
            // So the screen can say when the centre's documents print without a logo.
            'issuer' => [
                'name' => $staff->facility?->name,
                'logo_url' => $this->facilityLogoService->urlFor($staff->facility),
            ],
            'as_of' => $now->toIso8601String(),
        ];
    }

    /**
     * Project one journal row for the API. Amounts are decimal strings, signed against the bill.
     *
     * @return array<string, mixed>
     */
    public function format(BillingTransaction $row): array
    {
        $amount = Money::toCentavos($row->amount);

        return [
            'id' => $row->id,
            'transaction_number' => $row->transaction_number,
            'occurred_at' => $row->occurred_at?->toIso8601String(),
            'category' => $row->category->value,
            'category_label' => $row->category->label(),
            'type' => $row->type->value,
            'type_label' => $row->type->label(),
            'channel' => $row->channel->value,
            'channel_label' => $row->channel->label(),
            'payment_method' => $row->payment_method?->value,
            'payment_method_label' => $row->payment_method?->label(),
            'amount' => $row->amount,
            // Which way it moved the bill: a debit raises what is owed, a credit settles it.
            'direction' => $amount > 0 ? 'debit' : ($amount < 0 ? 'credit' : 'none'),
            'balance_after' => $row->balance_after,
            'billing_id' => $row->billing_id,
            'request' => $row->request ? ['id' => $row->request->id, 'reference_number' => $row->request->reference_number] : null,
            'payment_id' => $row->payment_id,
            'receipt' => $row->receipt ? ['id' => $row->receipt->id, 'receipt_number' => $row->receipt->receipt_number] : null,
            'statement' => $row->revision ? ['id' => $row->revision->id, 'document_number' => $row->revision->document_number] : null,
            'cash_session' => $row->cashSession ? ['id' => $row->cashSession->id, 'session_number' => $row->cashSession->session_number] : null,
            'reverses_transaction_number' => $row->reverses?->transaction_number,
            'reference' => $row->reference,
            'note' => $row->note,
            'recorded_by' => $row->recorder ? trim($row->recorder->first_name.' '.$row->recorder->last_name) : null,
        ];
    }

    /**
     * The journal of one centre, narrowed by the filters.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filtered(int $facilityId, array $filters): Builder
    {
        return BillingTransaction::query()
            ->where('facility_id', $facilityId)
            ->when(isset($filters['from']), function (Builder $query) use ($filters): void {
                $query->where('occurred_at', '>=', OperationalDay::boundsFor($filters['from'])[0]);
            })
            ->when(isset($filters['to']), function (Builder $query) use ($filters): void {
                $query->where('occurred_at', '<=', OperationalDay::boundsFor($filters['to'])[1]);
            })
            ->when(isset($filters['category']), fn (Builder $query) => $query->where('category', $filters['category']))
            ->when(isset($filters['type']), fn (Builder $query) => $query->where('type', $filters['type']))
            ->when(isset($filters['channel']), fn (Builder $query) => $query->where('channel', $filters['channel']))
            ->when(isset($filters['payment_method']), fn (Builder $query) => $query->where('payment_method', $filters['payment_method']))
            ->when(isset($filters['cash_session_id']), fn (Builder $query) => $query->where('cash_session_id', $filters['cash_session_id']))
            ->when(isset($filters['recorded_by']), fn (Builder $query) => $query->where('recorded_by', $filters['recorded_by']))
            ->when(isset($filters['search']), function (Builder $query) use ($filters): void {
                $term = '%'.$filters['search'].'%';

                $query->where(fn (Builder $match) => $match
                    ->where('transaction_number', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhereHas('request', fn (Builder $request) => $request->where('reference_number', 'like', $term)));
            });
    }

    /**
     * The totals of every row a query matches, by kind, in decimal strings.
     *
     * @return array<string, mixed>
     */
    private function totalsOf(Builder $query): array
    {
        $byType = (clone $query)
            ->get(['type', 'amount'])
            ->groupBy(fn (BillingTransaction $row): string => $row->type->value)
            ->map(fn ($rows): int => (int) $rows->sum(fn (BillingTransaction $row): int => Money::toCentavos($row->amount)));

        $of = fn (array $types): int => (int) collect($types)->sum(fn (TransactionType $type): int => $byType->get($type->value, 0));

        return [
            'count' => (clone $query)->count(),
            'charged' => Money::toDecimal($of(TransactionType::charges())),
            // Shown as positive money received, net of voids and corrections.
            'collected' => Money::toDecimal(-$of(TransactionType::collections())),
            'subsidised' => Money::toDecimal(-$of([TransactionType::Subsidy])),
            'settled_outside' => Money::toDecimal(-$of([TransactionType::ExternalSettlement])),
        ];
    }

    private function sumOf(Builder $query): int
    {
        return (int) $query->pluck('amount')->sum(fn (int|float|string $amount): int => Money::toCentavos($amount));
    }

    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw new HttpResponseException(response()->json([
            'message' => 'This account is not linked to a facility.',
            'code' => 'facility_missing',
        ], 404));
    }
}
