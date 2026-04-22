<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\ClientTransaction;
use App\Models\ReturnRecord;
use App\Models\Shift;
use App\Models\ShiftExpense;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShiftHistoryService
{
    public function __construct(
        private readonly PaymentAggregationService $payments,
    ) {
    }

    public function paginateHistory(int $tenantId, ?Carbon $from, ?Carbon $to, int $perPage): array
    {
        $pageQuery = $this->closedShiftQuery($tenantId, $from, $to);
        $paginator = $pageQuery->orderByDesc('closed_at')->paginate($perPage);

        $summaryItems = $this->closedShiftQuery($tenantId, $from, $to)
            ->orderByDesc('closed_at')
            ->get();

        $rows = $this->enrichRows(
            $tenantId,
            collect($paginator->items())->merge($summaryItems)->unique('id'),
        );

        $rowMap = $rows->keyBy(fn(array $row) => (int) $row['shift']->id);
        $items = collect($paginator->items())->map(function (Shift $shift) use ($rowMap) {
            $row = $rowMap->get((int) $shift->id);
            if (!$row) {
                return $shift;
            }

            $shift->setAttribute('finance', $row['finance']);
            $shift->setAttribute('reconcile_status', $row['status']);

            return $shift;
        })->values()->all();

        $summaryRows = $summaryItems->map(fn(Shift $shift) => $rowMap->get((int) $shift->id))
            ->filter()
            ->values();

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => $this->summarizeRows($summaryRows),
        ];
    }

    public function exportDataset(int $tenantId, ?Carbon $from, ?Carbon $to, int $limit): array
    {
        $items = $this->closedShiftQuery($tenantId, $from, $to)
            ->orderByDesc('closed_at')
            ->limit($limit)
            ->get();

        $rows = $this->enrichRows($tenantId, $items);
        $shiftIds = $items->pluck('id')->map(fn($id) => (int) $id)->values()->all();

        $peakExpenses = [];
        $peakReturns = [];

        if ($shiftIds !== []) {
            $peakExpenses = ShiftExpense::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('shift_id', $shiftIds)
                ->orderByRaw('ABS(amount) DESC')
                ->limit(30)
                ->get(['shift_id', 'spent_at', 'title', 'category', 'amount'])
                ->map(fn($row) => [
                    'shift_id' => (int) $row->shift_id,
                    'at' => (string) ($row->spent_at ?? ''),
                    'name' => (string) ($row->title ?? ''),
                    'category' => (string) ($row->category ?? ''),
                    'amount' => abs((int) $row->amount),
                ])
                ->values()
                ->all();

            $peakReturns = ReturnRecord::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('shift_id', $shiftIds)
                ->where('payment_method', 'cash')
                ->orderByRaw('ABS(amount) DESC')
                ->limit(30)
                ->get(['shift_id', 'created_at', 'client_id', 'amount'])
                ->map(fn($row) => [
                    'shift_id' => (int) $row->shift_id,
                    'at' => (string) ($row->created_at ?? ''),
                    'client_id' => (int) ($row->client_id ?? 0),
                    'amount' => abs((int) $row->amount),
                ])
                ->values()
                ->all();
        }

        return [
            'rows' => $rows->values()->all(),
            'summary' => $this->summarizeRows($rows),
            'peak_expenses' => $peakExpenses,
            'peak_returns' => $peakReturns,
        ];
    }

    public function show(int $tenantId, int|string $id): array
    {
        $shift = $this->closedShiftQuery($tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $rows = $this->enrichRows($tenantId, collect([$shift]));
        $row = $rows->first();
        $finance = $row['finance'];
        $shift->setAttribute('reconcile_status', $row['status']);

        $transactions = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->whereIn('type', ['topup', 'package', 'subscription'])
            ->with(['client:id,login,phone'])
            ->orderByDesc('id')
            ->get([
                'id',
                'client_id',
                'operator_id',
                'shift_id',
                'type',
                'amount',
                'bonus_amount',
                'payment_method',
                'comment',
                'created_at',
            ]);

        $transactionsByType = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->whereIn('type', ['topup', 'package', 'subscription'])
            ->selectRaw("
                type,
                COALESCE(payment_method, 'unknown') as payment_method,
                SUM(amount) as total_amount,
                SUM(COALESCE(bonus_amount, 0)) as total_bonus,
                COUNT(*) as ops_count
            ")
            ->groupBy('type', 'payment_method')
            ->orderBy('type')
            ->orderBy('payment_method')
            ->get();

        $returns = ReturnRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->with([
                'client:id,login,phone',
                'operator:id,login,name,role',
            ])
            ->orderByDesc('id')
            ->get([
                'id',
                'client_id',
                'operator_id',
                'shift_id',
                'type',
                'amount',
                'payment_method',
                'source_type',
                'source_id',
                'meta',
                'created_at',
            ]);

        $returnsByMethod = ReturnRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->selectRaw("
                COALESCE(payment_method, 'unknown') as payment_method,
                SUM(amount) as total_amount,
                COUNT(*) as ops_count
            ")
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get();

        $expenses = ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->orderByDesc('id')
            ->get([
                'id',
                'title',
                'category',
                'amount',
                'spent_at',
                'operator_id',
                'created_at',
            ]);

        $expensesByCategory = ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->selectRaw("COALESCE(category,'No category') as category, SUM(amount) as total")
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return [
            'shift' => $shift,
            'finance' => $finance,
            'transactions' => $transactions,
            'transactions_by_type' => $transactionsByType,
            'returns' => $returns,
            'returns_by_method' => $returnsByMethod,
            'expenses' => $expenses,
            'expenses_by_category' => $expensesByCategory,
        ];
    }

    public function closedShiftQuery(int $tenantId, ?Carbon $from = null, ?Carbon $to = null)
    {
        $query = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('closed_at')
            ->addSelect($this->nextShiftSelect())
            ->with([
                'openedBy:id,login,name,role',
                'closedBy:id,login,name,role',
            ])
            ->withSum('expenses as expenses_cash_total', 'amount');

        if ($from && $to) {
            $query->whereBetween('closed_at', [$from, $to]);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildShiftFinance(Shift $shift, ?object $txRow, ?object $returnRow, ?object $expenseRow): array
    {
        $topupsCash = (int) ($txRow->topups_cash ?? $shift->topups_cash_total ?? 0);
        $topupsCard = (int) ($txRow->topups_card ?? $shift->topups_card_total ?? 0);
        $packagesCash = (int) ($txRow->packages_cash ?? $shift->packages_cash_total ?? 0);
        $packagesCard = (int) ($txRow->packages_card ?? $shift->packages_card_total ?? 0);
        $subscriptionsCash = (int) ($txRow->subscriptions_cash ?? 0);
        $subscriptionsCard = (int) ($txRow->subscriptions_card ?? 0);

        $cashIn = (int) ($txRow->cash_in_total ?? ($topupsCash + $packagesCash + $subscriptionsCash));
        $cardIn = (int) ($txRow->card_in_total ?? ($topupsCard + $packagesCard + $subscriptionsCard));
        $grossIn = $cashIn + $cardIn;

        $returnsCash = abs((int) ($returnRow->returns_cash ?? 0));
        $returnsCard = abs((int) ($returnRow->returns_card ?? 0));
        $returnsBalance = abs((int) ($returnRow->returns_balance ?? 0));
        $returnsTotal = abs((int) ($returnRow->returns_total ?? $shift->returns_total ?? ($returnsCash + $returnsCard)));

        $expensesCash = abs((int) ($expenseRow->expenses_cash ?? $shift->expenses_cash_total ?? 0));
        $openingCash = (int) ($shift->opening_cash ?? 0);
        $closingCash = (int) ($shift->closing_cash ?? 0);

        $expectedCash = $openingCash + $cashIn - $expensesCash - $returnsCash;
        $diff = $closingCash - $expectedCash;
        $diffOverage = $diff > 0 ? $diff : 0;
        $diffShortage = $diff < 0 ? abs($diff) : 0;
        $netCash = $cashIn - $expensesCash - $returnsCash;

        $nextShiftId = (int) ($shift->next_shift_id ?? 0);
        $nextOpeningCash = $shift->next_shift_opening_cash;
        $nextOpeningCash = $nextOpeningCash === null ? null : (int) $nextOpeningCash;
        $nextOpenedAt = $shift->next_shift_opened_at ?? null;

        $carryToNext = 0;
        $shortageCarried = false;
        $shortagePartiallyCarried = false;

        if ($diffShortage > 0 && $nextOpeningCash !== null && $nextOpeningCash > 0) {
            $carryToNext = min($diffShortage, $nextOpeningCash);
            if ($carryToNext === $diffShortage) {
                $shortageCarried = true;
            } elseif ($carryToNext > 0) {
                $shortagePartiallyCarried = true;
            }
        }

        $shortageUnresolved = max(0, $diffShortage - $carryToNext);
        $effectiveDiff = $diff + $carryToNext;
        $effectiveOverage = $effectiveDiff > 0 ? $effectiveDiff : 0;
        $effectiveShortage = $effectiveDiff < 0 ? abs($effectiveDiff) : 0;
        $isEffectivelyReconciled = $effectiveDiff === 0;

        return [
            'topups_cash' => $topupsCash,
            'topups_card' => $topupsCard,
            'packages_cash' => $packagesCash,
            'packages_card' => $packagesCard,
            'subscriptions_cash' => $subscriptionsCash,
            'subscriptions_card' => $subscriptionsCard,
            'cash_in' => $cashIn,
            'card_in' => $cardIn,
            'gross_in' => $grossIn,
            'returns_cash' => $returnsCash,
            'returns_card' => $returnsCard,
            'returns_balance' => $returnsBalance,
            'returns_total' => $returnsTotal,
            'expenses_cash' => $expensesCash,
            'adjustments_total' => (int) ($shift->adjustments_total ?? 0),
            'opening_cash' => $openingCash,
            'expected_cash' => $expectedCash,
            'closing_cash' => $closingCash,
            'diff' => $diff,
            'diff_overage' => $diffOverage,
            'diff_shortage' => $diffShortage,
            'is_reconciled' => $diff === 0,
            'carry_to_next_opening' => $carryToNext,
            'shortage_carried_to_next' => $shortageCarried,
            'shortage_partially_carried_to_next' => $shortagePartiallyCarried,
            'shortage_unresolved' => $shortageUnresolved,
            'effective_diff' => $effectiveDiff,
            'effective_diff_overage' => $effectiveOverage,
            'effective_diff_shortage' => $effectiveShortage,
            'is_effectively_reconciled' => $isEffectivelyReconciled,
            'next_shift_id' => $nextShiftId > 0 ? $nextShiftId : null,
            'next_shift_opening_cash' => $nextOpeningCash,
            'next_shift_opened_at' => $nextOpenedAt ? (string) $nextOpenedAt : null,
            'net_cash' => $netCash,
            'topups_bonus_total' => (int) ($txRow->bonus_total ?? 0),
            'tx_count' => (int) ($txRow->tx_count ?? 0),
            'returns_count' => (int) ($returnRow->returns_count ?? 0),
            'expenses_count' => (int) ($expenseRow->expenses_count ?? 0),
        ];
    }

    public function resolveReconcileStatus(array $finance): string
    {
        if (!empty($finance['shortage_carried_to_next'])) {
            return 'carried';
        }
        if (!empty($finance['shortage_partially_carried_to_next'])) {
            return 'partial';
        }
        if (!empty($finance['is_effectively_reconciled'])) {
            return 'matched';
        }

        return ((int) ($finance['effective_diff'] ?? 0)) > 0 ? 'overage' : 'shortage';
    }

    private function enrichRows(int $tenantId, Collection $items): Collection
    {
        $shiftIds = $items->pluck('id')->map(fn($id) => (int) $id)->values()->all();
        $financeMap = $this->loadFinanceMap($tenantId, $shiftIds);

        return $items->map(function (Shift $shift) use ($financeMap) {
            $bucket = $financeMap[(int) $shift->id] ?? ['tx' => null, 'returns' => null, 'expenses' => null];
            $finance = $this->buildShiftFinance($shift, $bucket['tx'], $bucket['returns'], $bucket['expenses']);

            return [
                'shift' => $shift,
                'finance' => $finance,
                'status' => $this->resolveReconcileStatus($finance),
            ];
        })->values();
    }

    private function loadFinanceMap(int $tenantId, array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        $cash = $this->payments->quote(PaymentMethod::Cash->value);
        $card = $this->payments->quote(PaymentMethod::Card->value);
        $balance = $this->payments->quote(PaymentMethod::Balance->value);

        $txRows = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('shift_id', $shiftIds)
            ->whereIn('type', ['topup', 'package', 'subscription'])
            ->selectRaw("
                shift_id,
                SUM(CASE WHEN type = 'topup' AND payment_method = {$cash} THEN amount ELSE 0 END) as topups_cash,
                SUM(CASE WHEN type = 'topup' AND payment_method = {$card} THEN amount ELSE 0 END) as topups_card,
                SUM(CASE WHEN type = 'package' AND payment_method = {$cash} THEN amount ELSE 0 END) as packages_cash,
                SUM(CASE WHEN type = 'package' AND payment_method = {$card} THEN amount ELSE 0 END) as packages_card,
                SUM(CASE WHEN type = 'subscription' AND payment_method = {$cash} THEN amount ELSE 0 END) as subscriptions_cash,
                SUM(CASE WHEN type = 'subscription' AND payment_method = {$card} THEN amount ELSE 0 END) as subscriptions_card,
                SUM(CASE WHEN payment_method = {$cash} THEN amount ELSE 0 END) as cash_in_total,
                SUM(CASE WHEN payment_method = {$card} THEN amount ELSE 0 END) as card_in_total,
                SUM(COALESCE(bonus_amount, 0)) as bonus_total,
                COUNT(*) as tx_count
            ")
            ->groupBy('shift_id')
            ->get()
            ->keyBy('shift_id');

        $returnRows = ReturnRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('shift_id', $shiftIds)
            ->selectRaw("
                shift_id,
                SUM(CASE WHEN payment_method = {$cash} THEN ABS(amount) ELSE 0 END) as returns_cash,
                SUM(CASE WHEN payment_method = {$card} THEN ABS(amount) ELSE 0 END) as returns_card,
                SUM(CASE WHEN payment_method = {$balance} THEN ABS(amount) ELSE 0 END) as returns_balance,
                SUM(CASE WHEN payment_method IS NULL OR payment_method != {$balance} THEN ABS(amount) ELSE 0 END) as returns_total,
                COUNT(*) as returns_count
            ")
            ->groupBy('shift_id')
            ->get()
            ->keyBy('shift_id');

        $expenseRows = ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('shift_id', $shiftIds)
            ->selectRaw("
                shift_id,
                SUM(ABS(amount)) as expenses_cash,
                COUNT(*) as expenses_count
            ")
            ->groupBy('shift_id')
            ->get()
            ->keyBy('shift_id');

        $map = [];
        foreach ($shiftIds as $shiftId) {
            $map[(int) $shiftId] = [
                'tx' => $txRows->get((int) $shiftId),
                'returns' => $returnRows->get((int) $shiftId),
                'expenses' => $expenseRows->get((int) $shiftId),
            ];
        }

        return $map;
    }

    private function summarizeRows(Collection $rows): array
    {
        $count = (int) $rows->count();
        $sumCashIn = (int) $rows->sum(fn($row) => (int) ($row['finance']['cash_in'] ?? 0));
        $sumCardIn = (int) $rows->sum(fn($row) => (int) ($row['finance']['card_in'] ?? 0));
        $sumExpenses = (int) $rows->sum(fn($row) => (int) ($row['finance']['expenses_cash'] ?? 0));
        $sumReturnsCash = (int) $rows->sum(fn($row) => (int) ($row['finance']['returns_cash'] ?? 0));
        $sumReturnsTotal = (int) $rows->sum(fn($row) => (int) ($row['finance']['returns_total'] ?? 0));
        $sumClosing = (int) $rows->sum(fn($row) => (int) ($row['shift']->closing_cash ?? 0));
        $avgClosing = $count > 0 ? (int) round($sumClosing / $count) : 0;
        $sumNetCash = (int) $rows->sum(fn($row) => (int) ($row['finance']['net_cash'] ?? 0));
        $sumExpected = (int) $rows->sum(fn($row) => (int) ($row['finance']['expected_cash'] ?? 0));
        $sumOverage = (int) $rows->sum(fn($row) => (int) ($row['finance']['effective_diff_overage'] ?? 0));
        $sumShortage = (int) $rows->sum(fn($row) => (int) ($row['finance']['effective_diff_shortage'] ?? 0));
        $sumShortageRaw = (int) $rows->sum(fn($row) => (int) ($row['finance']['diff_shortage'] ?? 0));
        $sumCarried = (int) $rows->sum(fn($row) => (int) ($row['finance']['carry_to_next_opening'] ?? 0));
        $reconciledCount = (int) $rows->filter(fn($row) => !empty($row['finance']['is_effectively_reconciled']) || !empty($row['finance']['shortage_carried_to_next']))->count();

        return [
            'shifts_count' => $count,
            'cash_in' => $sumCashIn,
            'card_in' => $sumCardIn,
            'cash_out' => $sumExpenses + $sumReturnsCash,
            'net_cash' => $sumNetCash,
            'closing_sum' => $sumClosing,
            'closing_avg' => $avgClosing,
            'returns_total' => $sumReturnsTotal,
            'returns_cash' => $sumReturnsCash,
            'expenses_total' => $sumExpenses,
            'expected_sum' => $sumExpected,
            'diff_overage_sum' => $sumOverage,
            'diff_shortage_sum' => $sumShortage,
            'diff_shortage_raw_sum' => $sumShortageRaw,
            'carry_to_next_sum' => $sumCarried,
            'reconciled_count' => $reconciledCount,
            'unreconciled_count' => max(0, $count - $reconciledCount),
        ];
    }

    private function nextShiftSelect(): array
    {
        return [
            'next_shift_id' => DB::table('shifts as s2')
                ->select('id')
                ->whereColumn('s2.tenant_id', 'shifts.tenant_id')
                ->whereColumn('s2.opened_at', '>', 'shifts.closed_at')
                ->orderBy('s2.opened_at')
                ->limit(1),
            'next_shift_opening_cash' => DB::table('shifts as s2')
                ->select('opening_cash')
                ->whereColumn('s2.tenant_id', 'shifts.tenant_id')
                ->whereColumn('s2.opened_at', '>', 'shifts.closed_at')
                ->orderBy('s2.opened_at')
                ->limit(1),
            'next_shift_opened_at' => DB::table('shifts as s2')
                ->select('opened_at')
                ->whereColumn('s2.tenant_id', 'shifts.tenant_id')
                ->whereColumn('s2.opened_at', '>', 'shifts.closed_at')
                ->orderBy('s2.opened_at')
                ->limit(1),
        ];
    }
}
