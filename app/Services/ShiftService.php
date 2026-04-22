<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\ClientTransaction;
use App\Models\ReturnRecord;
use App\Models\Shift;
use App\Models\ShiftExpense;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftService
{
    public function __construct(
        private readonly PaymentAggregationService $payments,
        private readonly TelegramShiftNotifier $notifier,
    ) {
    }

    public function currentShift(int $tenantId): ?Shift
    {
        return Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->with([
                'openedBy:id,login,name,role',
                'closedBy:id,login,name,role',
            ])
            ->latest('id')
            ->first();
    }

    public function openShift(
        int $tenantId,
        int $operatorId,
        int $openingCash,
        ?Carbon $openedAt = null,
        array $meta = [],
        ?string $openedByLogin = null,
    ): Shift {
        $openedAt = ($openedAt ?: now())->copy();

        try {
            $shift = DB::transaction(function () use ($tenantId, $operatorId, $openingCash, $openedAt, $meta) {
                $this->lockTenant($tenantId);

                $existing = Shift::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('closed_at')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($existing) {
                    throw ValidationException::withMessages([
                        'shift' => 'Смена уже открыта',
                    ]);
                }

                return Shift::query()->create([
                    'tenant_id' => $tenantId,
                    'opened_by_operator_id' => $operatorId,
                    'opened_at' => $openedAt,
                    'opening_cash' => max(0, $openingCash),
                    'topups_cash_total' => 0,
                    'topups_card_total' => 0,
                    'packages_cash_total' => 0,
                    'packages_card_total' => 0,
                    'returns_total' => 0,
                    'diff_overage' => 0,
                    'diff_shortage' => 0,
                    'adjustments_total' => 0,
                    'status' => 'open',
                    'meta' => $meta !== [] ? $meta : null,
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isOneOpenShiftViolation($exception)) {
                throw ValidationException::withMessages([
                    'shift' => 'Смена уже открыта',
                ]);
            }

            throw $exception;
        }

        $shift->load(['openedBy:id,login,name,role']);

        $this->notifier->shiftOpened($tenantId, [
            'shift_id' => $shift->id,
            'opened_by' => $shift->openedBy?->login ?? $openedByLogin ?? ('#' . $operatorId),
            'opened_at' => (string) $shift->opened_at,
            'opening_cash' => (int) $shift->opening_cash,
            'checklist' => $meta['checklist'] ?? null,
        ]);

        return $shift;
    }

    /**
     * @return array{shift: Shift, snapshot: array<string, int>}
     */
    public function closeShift(
        int $tenantId,
        Shift $shift,
        int $operatorId,
        ?int $closingCash = null,
        ?Carbon $closedAt = null,
        array $meta = [],
        ?string $closedByLogin = null,
    ): array {
        $closedAt = ($closedAt ?: now())->copy();
        $closeSnapshot = [];

        DB::transaction(function () use (
            $tenantId,
            $shift,
            $operatorId,
            $closingCash,
            $closedAt,
            $meta,
            &$closeSnapshot,
        ) {
            $this->lockTenant($tenantId);

            $locked = Shift::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->first();

            if (!$locked || $locked->closed_at !== null) {
                throw ValidationException::withMessages([
                    'shift' => 'Смена не открыта',
                ]);
            }

            $topupBase = ClientTransaction::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $locked->id)
                ->where('type', 'topup');

            $topupTotals = (clone $topupBase)
                ->selectRaw('payment_method, COUNT(*) as ops_count, COALESCE(SUM(amount), 0) as amount_sum, COALESCE(SUM(bonus_amount), 0) as bonus_sum')
                ->groupBy('payment_method')
                ->get()
                ->keyBy('payment_method');

            $cashMethod = PaymentMethod::Cash->value;
            $cardMethod = PaymentMethod::Card->value;
            $balanceMethod = PaymentMethod::Balance->value;

            $cashTotal = (int) ($topupTotals[$cashMethod]->amount_sum ?? 0);
            $cardTotal = (int) ($topupTotals[$cardMethod]->amount_sum ?? 0);
            $bonusTotal = (int) collect($topupTotals)->sum(fn($row) => (int) ($row->bonus_sum ?? 0));
            $opsCount = (int) collect($topupTotals)->sum(fn($row) => (int) ($row->ops_count ?? 0));

            $returnsBase = ReturnRecord::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $locked->id);

            $returnTotals = $this->payments->returnSummary(clone $returnsBase, true);
            $returnsTotal = (int) (clone $returnsBase)
                ->where(function ($query) use ($balanceMethod) {
                    $query->whereNull('payment_method')
                        ->orWhere('payment_method', '!=', $balanceMethod);
                })
                ->sum(DB::raw('ABS(amount)'));

            $returnsCashTotal = (int) ($returnTotals->returns_cash_total ?? 0);

            $salesBase = ClientTransaction::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $locked->id)
                ->whereIn('type', ['topup', 'package', 'subscription']);

            $summary = $this->payments->salesSummary(clone $salesBase, ['topup', 'package', 'subscription']);
            $summaryCash = (int) ($summary->cash_sales_total ?? 0);

            $expensesTotal = (int) ShiftExpense::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $locked->id)
                ->sum(DB::raw('ABS(amount)'));

            $expectedCash = (int) $locked->opening_cash + $summaryCash - $expensesTotal - $returnsCashTotal;
            $resolvedClosingCash = $closingCash === null ? $expectedCash : max(0, $closingCash);
            $diff = $resolvedClosingCash - $expectedCash;
            $diffOver = $diff > 0 ? $diff : 0;
            $diffShort = $diff < 0 ? abs($diff) : 0;

            $shiftMeta = is_array($locked->meta) ? $locked->meta : [];
            $shiftMeta['topups_bonus_total'] = $bonusTotal;
            $shiftMeta['topups_ops_count'] = $opsCount;
            foreach ($meta as $key => $value) {
                $shiftMeta[$key] = $value;
            }

            $locked->update([
                'closed_at' => $closedAt,
                'closing_cash' => $resolvedClosingCash,
                'closed_by_operator_id' => $operatorId,
                'topups_cash_total' => $cashTotal,
                'topups_card_total' => $cardTotal,
                'returns_total' => $returnsTotal,
                'diff_overage' => $diffOver,
                'diff_shortage' => $diffShort,
                'status' => 'closed',
                'meta' => $shiftMeta,
            ]);

            $closeSnapshot = [
                'topups_cash_total' => $cashTotal,
                'topups_card_total' => $cardTotal,
                'topups_bonus_total' => $bonusTotal,
                'topups_ops_count' => $opsCount,
                'returns_total' => $returnsTotal,
                'returns_cash_total' => $returnsCashTotal,
                'expenses_total' => $expensesTotal,
                'expected_cash' => $expectedCash,
            ];
        });

        $shift->refresh()->load([
            'openedBy:id,login,name,role',
            'closedBy:id,login,name,role',
        ]);

        $this->notifier->shiftClosed($tenantId, [
            'shift_id' => $shift->id,
            'opened_by' => $shift->openedBy?->login ?? '-',
            'closed_by' => $shift->closedBy?->login ?? $closedByLogin ?? ('#' . $operatorId),
            'opened_at' => (string) $shift->opened_at,
            'closed_at' => (string) $shift->closed_at,
            'opening_cash' => (int) $shift->opening_cash,
            'closing_cash' => (int) $shift->closing_cash,
            'diff_overage' => (int) $shift->diff_overage,
            'diff_shortage' => (int) $shift->diff_shortage,
            ...$closeSnapshot,
        ]);

        return [
            'shift' => $shift,
            'snapshot' => $closeSnapshot,
        ];
    }

    public function buildReport(int $tenantId, Carbon $from, Carbon $to, int $limit = 10): array
    {
        $txBase = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->where('type', 'topup');

        $totals = (clone $txBase)
            ->selectRaw('payment_method, COALESCE(SUM(amount), 0) as amount_sum')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $cash = (int) ($totals[PaymentMethod::Cash->value]->amount_sum ?? 0);
        $card = (int) ($totals[PaymentMethod::Card->value]->amount_sum ?? 0);
        $bonus = (int) (clone $txBase)->sum('bonus_amount');
        $count = (int) (clone $txBase)->count();

        $recent = (clone $txBase)
            ->orderByDesc('id')
            ->limit($limit)
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

        $shifts = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('opened_at', [$from, $to])
            ->with(['openedBy:id,login,name,role', 'closedBy:id,login,name,role'])
            ->orderByDesc('opened_at')
            ->get([
                'id',
                'tenant_id',
                'opened_by_operator_id',
                'closed_by_operator_id',
                'opened_at',
                'closed_at',
                'opening_cash',
                'closing_cash',
                'topups_cash_total',
                'topups_card_total',
                'packages_cash_total',
                'packages_card_total',
                'returns_total',
                'diff_overage',
                'diff_shortage',
                'adjustments_total',
                'status',
            ]);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'cash_total' => $cash,
                'card_total' => $card,
                'total_amount' => $cash + $card,
                'bonus_total' => $bonus,
                'topups_count' => $count,
            ],
            'recent' => $recent,
            'shifts' => $shifts,
        ];
    }

    public function buildCurrentSummary(int $tenantId): ?array
    {
        $shift = $this->currentShift($tenantId);
        if (!$shift) {
            return null;
        }

        $base = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->whereIn('type', ['topup', 'package', 'subscription']);

        $totals = (clone $base)
            ->selectRaw('payment_method, COUNT(*) as ops_count, COALESCE(SUM(amount), 0) as amount_sum, COALESCE(SUM(bonus_amount), 0) as bonus_sum')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $cashAmount = (int) ($totals[PaymentMethod::Cash->value]->amount_sum ?? 0);
        $cardAmount = (int) ($totals[PaymentMethod::Card->value]->amount_sum ?? 0);
        $grossAmount = $cashAmount + $cardAmount;
        $bonusTotal = (int) collect($totals)->sum(fn($row) => (int) ($row->bonus_sum ?? 0));
        $opsCount = (int) collect($totals)->sum(fn($row) => (int) ($row->ops_count ?? 0));

        $byOperator = ClientTransaction::query()
            ->from('client_transactions as ct')
            ->leftJoin('operators as o', 'o.id', '=', 'ct.operator_id')
            ->where('ct.tenant_id', $tenantId)
            ->where('ct.shift_id', $shift->id)
            ->whereIn('ct.type', ['topup', 'package', 'subscription'])
            ->selectRaw('
                ct.operator_id,
                o.login as operator_login,
                o.name as operator_name,
                SUM(CASE WHEN ct.payment_method = ? THEN ct.amount ELSE 0 END) as cash_amount,
                SUM(CASE WHEN ct.payment_method = ? THEN ct.amount ELSE 0 END) as card_amount,
                SUM(COALESCE(ct.amount, 0)) as gross_amount,
                SUM(COALESCE(ct.bonus_amount, 0)) as bonus_amount,
                COUNT(*) as ops_count
            ', [PaymentMethod::Cash->value, PaymentMethod::Card->value])
            ->groupBy('ct.operator_id', 'o.login', 'o.name')
            ->orderByDesc('gross_amount')
            ->get()
            ->map(function ($row) {
                $operatorId = $row->operator_id === null ? null : (int) $row->operator_id;
                $operatorName = $row->operator_name ?: ($row->operator_login ?: ($operatorId ? ('operator #' . $operatorId) : 'unknown'));

                return [
                    'operator_id' => $operatorId,
                    'operator' => $operatorName,
                    'cash' => (int) ($row->cash_amount ?? 0),
                    'card' => (int) ($row->card_amount ?? 0),
                    'gross' => (int) ($row->gross_amount ?? 0),
                    'bonus' => (int) ($row->bonus_amount ?? 0),
                    'ops_count' => (int) ($row->ops_count ?? 0),
                ];
            })
            ->values();

        $openingCash = (int) ($shift->opening_cash ?? 0);

        $expensesTotal = (int) ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->sum(DB::raw('ABS(amount)'));

        $expectedCash = $openingCash + $cashAmount - $expensesTotal;

        $previousShift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('closed_at')
            ->where('closed_at', '<=', $shift->opened_at ?? now())
            ->with([
                'openedBy:id,login,name,role',
                'closedBy:id,login,name,role',
            ])
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->first([
                'id',
                'opened_at',
                'closed_at',
                'opening_cash',
                'closing_cash',
                'topups_cash_total',
                'packages_cash_total',
                'diff_overage',
                'diff_shortage',
                'opened_by_operator_id',
                'closed_by_operator_id',
            ]);

        $handover = null;
        if ($previousShift) {
            $prevReturnsCash = (int) ReturnRecord::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $previousShift->id)
                ->where('payment_method', PaymentMethod::Cash->value)
                ->sum(DB::raw('ABS(amount)'));

            $prevExpensesCash = (int) ShiftExpense::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $previousShift->id)
                ->sum(DB::raw('ABS(amount)'));

            $prevCashIncome = (int) ($previousShift->topups_cash_total ?? 0) + (int) ($previousShift->packages_cash_total ?? 0);
            $prevExpectedCash = (int) ($previousShift->opening_cash ?? 0) + $prevCashIncome - $prevExpensesCash - $prevReturnsCash;
            $prevClosingCash = (int) ($previousShift->closing_cash ?? 0);
            $prevDiffApprox = $prevClosingCash - $prevExpectedCash;

            $prevShortage = max((int) ($previousShift->diff_shortage ?? 0), max(0, -1 * $prevDiffApprox));
            $carryAmount = min($prevShortage, $openingCash);
            $unresolvedShortage = max(0, $prevShortage - $carryAmount);
            $carryRate = $prevShortage > 0 ? round(($carryAmount / $prevShortage) * 100, 1) : 0;

            $carryStatus = 'none';
            if ($prevShortage > 0) {
                $carryStatus = $carryAmount <= 0 ? 'not_carried' : ($unresolvedShortage > 0 ? 'partial' : 'full');
            }

            $handover = [
                'previous_shift' => [
                    'id' => (int) $previousShift->id,
                    'opened_at' => $previousShift->opened_at,
                    'closed_at' => $previousShift->closed_at,
                    'opening_cash' => (int) ($previousShift->opening_cash ?? 0),
                    'closing_cash' => $prevClosingCash,
                    'expected_cash' => $prevExpectedCash,
                    'diff_shortage' => (int) ($previousShift->diff_shortage ?? 0),
                    'diff_overage' => (int) ($previousShift->diff_overage ?? 0),
                    'opened_by' => $previousShift->openedBy,
                    'closed_by' => $previousShift->closedBy,
                ],
                'carry' => [
                    'status' => $carryStatus,
                    'previous_shortage' => $prevShortage,
                    'carried_amount' => $carryAmount,
                    'unresolved_shortage' => $unresolvedShortage,
                    'carry_rate' => $carryRate,
                    'current_opening_cash' => $openingCash,
                ],
            ];
        }

        return [
            'shift' => [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
                'opening_cash' => $openingCash,
                'closing_cash' => (int) ($shift->closing_cash ?? 0),
                'opened_by' => $shift->openedBy,
                'closed_by' => $shift->closedBy,
            ],
            'totals' => [
                'cash' => $cashAmount,
                'card' => $cardAmount,
                'cash_total' => $cashAmount,
                'card_total' => $cardAmount,
                'bonus' => $bonusTotal,
                'bonus_total' => $bonusTotal,
                'ops_count' => $opsCount,
                'operations_count' => $opsCount,
                'gross' => $grossAmount,
                'gross_total' => $grossAmount,
                'total_amount' => $grossAmount,
                'shift_income' => $grossAmount,
                'expenses' => $expensesTotal,
            ],
            'expected' => [
                'cash_now' => $expectedCash,
            ],
            'by_operator' => $byOperator,
            'handover' => $handover,
        ];
    }

    private function lockTenant(int $tenantId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [$tenantId * 1000000 + 710001]);
    }

    private function isOneOpenShiftViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return $sqlState === '23505'
            && str_contains($exception->getMessage(), 'shifts_one_open_per_tenant_idx');
    }
}
