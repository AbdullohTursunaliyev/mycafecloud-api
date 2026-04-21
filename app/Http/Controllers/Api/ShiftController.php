<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientTransaction;
use App\Models\ReturnRecord;
use App\Models\Shift;
use App\Service\TelegramShiftNotifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    // GET /api/shifts/current
    public function current(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $shift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->with([
                'openedBy:id,login,name,role',
                'closedBy:id,login,name,role',
            ])
            ->latest('id')
            ->first();

        return response()->json(['data' => $shift]);
    }

    // POST /api/shifts/open
    public function open(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $operatorId = $request->user()->id;

        $data = $request->validate([
            'opening_cash' => ['required', 'integer', 'min:0'],
        ]);

        $exists = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'shift' => 'Смена уже открыта',
            ]);
        }

        $shift = Shift::create([
            'tenant_id' => $tenantId,
            'opened_by_operator_id' => $operatorId,
            'opened_at' => now(),
            'opening_cash' => (int)$data['opening_cash'],

            // agar xohlasangiz default 0 qilib ketamiz:
            'topups_cash_total' => 0,
            'topups_card_total' => 0,
            'packages_cash_total' => 0,
            'packages_card_total' => 0,
            'returns_total' => 0,
            'diff_overage' => 0,
            'diff_shortage' => 0,
            'adjustments_total' => 0,
            'status' => 'open',
            'meta' => null,
        ]);

        $shift->load(['openedBy:id,login,name,role']);

        TelegramShiftNotifier::shiftOpened($tenantId, [
            'shift_id' => $shift->id,
            'opened_by' => $shift->openedBy?->login ?? $request->user()->login ?? ('#' . $operatorId),
            'opened_at' => (string)$shift->opened_at,
            'opening_cash' => (int)$shift->opening_cash,
        ]);

        return response()->json(['data' => $shift], 201);
    }


    // POST /api/shifts/close
    // POST /api/shifts/close
    public function close(Request $request)
    {
        $tenantId   = $request->user()->tenant_id;
        $operatorId = $request->user()->id;

        $data = $request->validate([
            'closing_cash' => ['required', 'integer', 'min:0'],
        ]);

        /** @var Shift|null $shift */
        $shift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();

        if (!$shift) {
            throw ValidationException::withMessages([
                'shift' => 'Смена не открыта',
            ]);
        }

        $closeSnapshot = [];

        DB::transaction(function () use ($shift, $tenantId, $operatorId, $data, &$closeSnapshot) {

            // 1) shift transactions for snapshot totals
            $base = ClientTransaction::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $shift->id)
                ->where('type', 'topup');

            $cashTotal = (int) (clone $base)->where('payment_method', 'cash')->sum('amount');
            $cardTotal = (int) (clone $base)->where('payment_method', 'card')->sum('amount');

            // bonus alohida saqlamoqchi bo‘lsangiz meta'ga yozib qo'yamiz
            $bonusTotal = (int) (clone $base)->sum('bonus_amount');
            $opsCount   = (int) (clone $base)->count();

            // returns total (exclude balance refunds)
            $returnsTotal = (int) ReturnRecord::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $shift->id)
                ->where(function ($q) {
                    $q->whereNull('payment_method')
                      ->orWhere('payment_method', '!=', 'balance');
                })
                ->sum(DB::raw('ABS(amount)'));

            $returnsCashTotal = (int) ReturnRecord::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $shift->id)
                ->where('payment_method', 'cash')
                ->sum(DB::raw('ABS(amount)'));

            // expected cash calculation based on same logic as current summary
            $summaryBase = ClientTransaction::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $shift->id)
                ->whereIn('type', ['topup','package','subscription']);

            $summaryCash = (int) (clone $summaryBase)->where('payment_method', 'cash')->sum('amount');

            $expensesTotal = (int) \App\Models\ShiftExpense::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $shift->id)
                ->sum(DB::raw('ABS(amount)'));

            $expectedCash = (int) $shift->opening_cash + $summaryCash - $expensesTotal - $returnsCashTotal;
            $diff = (int) $data['closing_cash'] - $expectedCash;
            $diffOver = $diff > 0 ? $diff : 0;
            $diffShort = $diff < 0 ? abs($diff) : 0;

            // 2) shift snapshot update
            $meta = $shift->meta ?? [];
            $meta['topups_bonus_total'] = $bonusTotal;
            $meta['topups_ops_count']   = $opsCount;

            $shift->update([
                'closed_at'               => now(),
                'closing_cash'            => (int) $data['closing_cash'],
                'closed_by_operator_id'   => $operatorId,

                // SNAPSHOT TOTALS:
                'topups_cash_total'       => $cashTotal,
                'topups_card_total'       => $cardTotal,
                'returns_total'           => $returnsTotal,
                'diff_overage'            => $diffOver,
                'diff_shortage'           => $diffShort,

                // sizda hozircha package/adjustments yo‘q bo‘lsa 0 qolsin
                // 'packages_cash_total'  => ...
                // 'packages_card_total'  => ...
                // 'adjustments_total'    => ...

                'status'                  => 'closed',
                'meta'                    => $meta,
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

        TelegramShiftNotifier::shiftClosed($tenantId, [
            'shift_id' => $shift->id,
            'opened_by' => $shift->openedBy?->login ?? '-',
            'closed_by' => $shift->closedBy?->login ?? $request->user()->login ?? ('#' . $operatorId),
            'opened_at' => (string)$shift->opened_at,
            'closed_at' => (string)$shift->closed_at,
            'opening_cash' => (int)$shift->opening_cash,
            'closing_cash' => (int)$shift->closing_cash,
            'diff_overage' => (int)$shift->diff_overage,
            'diff_shortage' => (int)$shift->diff_shortage,
            ...$closeSnapshot,
        ]);

        return response()->json(['data' => $shift]);
    }



    // GET /api/shifts/report?from=YYYY-MM-DD&to=YYYY-MM-DD&limit=10
    public function report(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        $limit = (int)($data['limit'] ?? 10);

        // Client topup’lar (shift_id bo‘lsa yaxshi, bo‘lmasa ham bugungi hisobotda tushadi)
        $txBase = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->where('type', 'topup');

        $cash = (int)(clone $txBase)->where('payment_method', 'cash')->sum('amount');
        $card = (int)(clone $txBase)->where('payment_method', 'card')->sum('amount');
        $bonus = (int)(clone $txBase)->sum('bonus_amount');
        $count = (int)(clone $txBase)->count();

        $recent = (clone $txBase)
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id','client_id','operator_id','shift_id','type',
                'amount','bonus_amount','payment_method','comment','created_at'
            ]);

        $shifts = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('opened_at', [$from, $to])
            ->with(['openedBy:id,login,name,role','closedBy:id,login,name,role'])
            ->orderByDesc('opened_at')
            ->get([
                'id','tenant_id','opened_by_operator_id','closed_by_operator_id',
                'opened_at','closed_at','opening_cash','closing_cash',
                'topups_cash_total','topups_card_total',
                'packages_cash_total','packages_card_total',
                'returns_total',
                'diff_overage','diff_shortage',
                'adjustments_total','status'
            ]);


        return response()->json([
            'data' => [
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
            ],
        ]);
    }

    public function currentSummary(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $shift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->with(['openedBy:id,login,name,role','closedBy:id,login,name,role'])
            ->latest('id')
            ->first();

        if (!$shift) {
            return response()->json([
                'data' => null,
                'message' => 'Shift is not open'
            ], 200);
        }

        $base = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->whereIn('type', ['topup','package','subscription']); // <<< MUHIM

        $totals = (clone $base)
            ->selectRaw("
            payment_method,
            COUNT(*) as ops_count,
            COALESCE(SUM(amount),0) as amount_sum,
            COALESCE(SUM(bonus_amount),0) as bonus_sum
        ")
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $cashAmount = (int)($totals['cash']->amount_sum ?? 0);
        $cardAmount = (int)($totals['card']->amount_sum ?? 0);
        $grossAmount = $cashAmount + $cardAmount;
        $bonusTotal = (int)(
            ($totals['cash']->bonus_sum ?? 0) +
            ($totals['card']->bonus_sum ?? 0)
        );

        $opsCount = (int)(
            ($totals['cash']->ops_count ?? 0) +
            ($totals['card']->ops_count ?? 0)
        );

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
            ', ['cash', 'card'])
            ->groupBy('ct.operator_id', 'o.login', 'o.name')
            ->orderByDesc('gross_amount')
            ->get()
            ->map(function ($row) {
                $operatorId = $row->operator_id === null ? null : (int)$row->operator_id;
                $operatorName = $row->operator_name ?: ($row->operator_login ?: ($operatorId ? ('operator #' . $operatorId) : 'unknown'));

                return [
                    'operator_id' => $operatorId,
                    'operator' => $operatorName,
                    'cash' => (int)($row->cash_amount ?? 0),
                    'card' => (int)($row->card_amount ?? 0),
                    'gross' => (int)($row->gross_amount ?? 0),
                    'bonus' => (int)($row->bonus_amount ?? 0),
                    'ops_count' => (int)($row->ops_count ?? 0),
                ];
            })
            ->values();

        // ✅ DBda opening_cash bor:
        $openingCash = (int)($shift->opening_cash ?? 0);

        $expensesTotal = (int) \App\Models\ShiftExpense::query()
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
                ->where('payment_method', 'cash')
                ->sum(DB::raw('ABS(amount)'));

            $prevExpensesCash = (int) \App\Models\ShiftExpense::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $previousShift->id)
                ->sum(DB::raw('ABS(amount)'));

            $prevCashIncome = (int)($previousShift->topups_cash_total ?? 0) + (int)($previousShift->packages_cash_total ?? 0);
            $prevExpectedCash = (int)($previousShift->opening_cash ?? 0) + $prevCashIncome - $prevExpensesCash - $prevReturnsCash;
            $prevClosingCash = (int)($previousShift->closing_cash ?? 0);
            $prevDiffApprox = $prevClosingCash - $prevExpectedCash;

            $prevShortage = max((int)($previousShift->diff_shortage ?? 0), max(0, -1 * $prevDiffApprox));
            $carryAmount = min($prevShortage, $openingCash);
            $unresolvedShortage = max(0, $prevShortage - $carryAmount);
            $carryRate = $prevShortage > 0 ? round(($carryAmount / $prevShortage) * 100, 1) : 0;

            $carryStatus = 'none';
            if ($prevShortage > 0) {
                if ($carryAmount <= 0) {
                    $carryStatus = 'not_carried';
                } elseif ($unresolvedShortage > 0) {
                    $carryStatus = 'partial';
                } else {
                    $carryStatus = 'full';
                }
            }

            $handover = [
                'previous_shift' => [
                    'id' => (int)$previousShift->id,
                    'opened_at' => $previousShift->opened_at,
                    'closed_at' => $previousShift->closed_at,
                    'opening_cash' => (int)($previousShift->opening_cash ?? 0),
                    'closing_cash' => $prevClosingCash,
                    'expected_cash' => $prevExpectedCash,
                    'diff_shortage' => (int)($previousShift->diff_shortage ?? 0),
                    'diff_overage' => (int)($previousShift->diff_overage ?? 0),
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



        return response()->json([
            'data' => [
                'shift' => [
                    'id' => $shift->id,
                    'opened_at' => $shift->opened_at,
                    'closed_at' => $shift->closed_at,
                    'opening_cash' => $openingCash,
                    'closing_cash' => (int)($shift->closing_cash ?? 0),
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
            ]
        ]);
    }

}
