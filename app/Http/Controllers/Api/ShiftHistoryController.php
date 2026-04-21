<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientTransaction;
use App\Models\ReturnRecord;
use App\Models\Shift;
use App\Models\ShiftExpense;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftHistoryController extends Controller
{
    private function loadFinanceMap(int $tenantId, array $shiftIds): array
    {
        if (!$shiftIds) {
            return [];
        }

        $txRows = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('shift_id', $shiftIds)
            ->whereIn('type', ['topup', 'package', 'subscription'])
            ->selectRaw("
                shift_id,
                SUM(CASE WHEN type = 'topup' AND payment_method = 'cash' THEN amount ELSE 0 END) as topups_cash,
                SUM(CASE WHEN type = 'topup' AND payment_method = 'card' THEN amount ELSE 0 END) as topups_card,
                SUM(CASE WHEN type = 'package' AND payment_method = 'cash' THEN amount ELSE 0 END) as packages_cash,
                SUM(CASE WHEN type = 'package' AND payment_method = 'card' THEN amount ELSE 0 END) as packages_card,
                SUM(CASE WHEN type = 'subscription' AND payment_method = 'cash' THEN amount ELSE 0 END) as subscriptions_cash,
                SUM(CASE WHEN type = 'subscription' AND payment_method = 'card' THEN amount ELSE 0 END) as subscriptions_card,
                SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash_in_total,
                SUM(CASE WHEN payment_method = 'card' THEN amount ELSE 0 END) as card_in_total,
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
                SUM(CASE WHEN payment_method = 'cash' THEN ABS(amount) ELSE 0 END) as returns_cash,
                SUM(CASE WHEN payment_method = 'card' THEN ABS(amount) ELSE 0 END) as returns_card,
                SUM(CASE WHEN payment_method = 'balance' THEN ABS(amount) ELSE 0 END) as returns_balance,
                SUM(CASE WHEN payment_method IS NULL OR payment_method != 'balance' THEN ABS(amount) ELSE 0 END) as returns_total,
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
            $map[(int)$shiftId] = [
                'tx' => $txRows->get((int)$shiftId),
                'returns' => $returnRows->get((int)$shiftId),
                'expenses' => $expenseRows->get((int)$shiftId),
            ];
        }

        return $map;
    }

    private function buildShiftFinance(Shift $shift, ?object $txRow, ?object $returnRow, ?object $expenseRow): array
    {
        $topupsCash = (int)($txRow->topups_cash ?? $shift->topups_cash_total ?? 0);
        $topupsCard = (int)($txRow->topups_card ?? $shift->topups_card_total ?? 0);
        $packagesCash = (int)($txRow->packages_cash ?? $shift->packages_cash_total ?? 0);
        $packagesCard = (int)($txRow->packages_card ?? $shift->packages_card_total ?? 0);
        $subscriptionsCash = (int)($txRow->subscriptions_cash ?? 0);
        $subscriptionsCard = (int)($txRow->subscriptions_card ?? 0);

        $cashIn = (int)($txRow->cash_in_total ?? ($topupsCash + $packagesCash + $subscriptionsCash));
        $cardIn = (int)($txRow->card_in_total ?? ($topupsCard + $packagesCard + $subscriptionsCard));
        $grossIn = $cashIn + $cardIn;

        $returnsCash = abs((int)($returnRow->returns_cash ?? 0));
        $returnsCard = abs((int)($returnRow->returns_card ?? 0));
        $returnsBalance = abs((int)($returnRow->returns_balance ?? 0));
        $returnsTotal = abs((int)($returnRow->returns_total ?? $shift->returns_total ?? ($returnsCash + $returnsCard)));

        $expensesCash = abs((int)($expenseRow->expenses_cash ?? $shift->expenses_cash_total ?? 0));
        $openingCash = (int)($shift->opening_cash ?? 0);
        $closingCash = (int)($shift->closing_cash ?? 0);

        $expectedCash = $openingCash + $cashIn - $expensesCash - $returnsCash;
        $diff = $closingCash - $expectedCash;
        $diffOverage = $diff > 0 ? $diff : 0;
        $diffShortage = $diff < 0 ? abs($diff) : 0;
        $netCash = $cashIn - $expensesCash - $returnsCash;

        $nextShiftId = (int)($shift->next_shift_id ?? 0);
        $nextOpeningCash = ($shift->next_shift_opening_cash ?? null);
        $nextOpeningCash = $nextOpeningCash === null ? null : (int)$nextOpeningCash;
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
            'adjustments_total' => (int)($shift->adjustments_total ?? 0),
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
            'next_shift_opened_at' => $nextOpenedAt ? (string)$nextOpenedAt : null,
            'net_cash' => $netCash,
            'topups_bonus_total' => (int)($txRow->bonus_total ?? 0),
            'tx_count' => (int)($txRow->tx_count ?? 0),
            'returns_count' => (int)($returnRow->returns_count ?? 0),
            'expenses_count' => (int)($expenseRow->expenses_count ?? 0),
        ];
    }

    private function resolveReconcileStatus(array $finance): string
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

        return ((int)($finance['effective_diff'] ?? 0)) > 0 ? 'overage' : 'shortage';
    }

    // GET /api/shifts/history?from=YYYY-MM-DD&to=YYYY-MM-DD&page=1&per_page=10
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $perPage = (int)($data['per_page'] ?? 10);
        $from = !empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
        $to = !empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null;

        $pageQuery = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('closed_at')
            ->addSelect([
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
            ])
            ->with([
                'openedBy:id,login,name,role',
                'closedBy:id,login,name,role',
            ])
            ->withSum('expenses as expenses_cash_total', 'amount');

        if ($from && $to) {
            $pageQuery->whereBetween('closed_at', [$from, $to]);
        }

        $p = $pageQuery->orderByDesc('closed_at')->paginate($perPage);

        $items = collect($p->items());

        $summaryQuery = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('closed_at')
            ->addSelect([
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
            ])
            ->withSum('expenses as expenses_cash_total', 'amount');

        if ($from && $to) {
            $summaryQuery->whereBetween('closed_at', [$from, $to]);
        }

        $summaryItems = $summaryQuery->orderByDesc('closed_at')->get();

        $allShiftIds = $items->pluck('id')
            ->merge($summaryItems->pluck('id'))
            ->map(fn($id) => (int)$id)
            ->unique()
            ->values()
            ->all();
        $financeMap = $this->loadFinanceMap((int)$tenantId, $allShiftIds);

        $items->each(function (Shift $shift) use ($financeMap) {
            $bucket = $financeMap[(int)$shift->id] ?? ['tx' => null, 'returns' => null, 'expenses' => null];
            $finance = $this->buildShiftFinance($shift, $bucket['tx'], $bucket['returns'], $bucket['expenses']);
            $shift->setAttribute('finance', $finance);
            $shift->setAttribute('reconcile_status', $this->resolveReconcileStatus($finance));
        });

        $summaryRows = $summaryItems->map(function (Shift $shift) use ($financeMap) {
            $bucket = $financeMap[(int)$shift->id] ?? ['tx' => null, 'returns' => null, 'expenses' => null];
            $finance = $this->buildShiftFinance($shift, $bucket['tx'], $bucket['returns'], $bucket['expenses']);
            return [
                'shift' => $shift,
                'finance' => $finance,
            ];
        });

        $summaryCount = (int)$summaryRows->count();
        $sumCashIn = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['cash_in'] ?? 0));
        $sumCardIn = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['card_in'] ?? 0));
        $sumExpenses = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['expenses_cash'] ?? 0));
        $sumReturnsCash = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['returns_cash'] ?? 0));
        $sumReturnsTotal = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['returns_total'] ?? 0));
        $sumClosing = (int)$summaryRows->sum(fn($r) => (int)($r['shift']->closing_cash ?? 0));
        $avgClosing = $summaryCount ? (int)round($sumClosing / $summaryCount) : 0;
        $sumNetCash = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['net_cash'] ?? 0));
        $sumExpected = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['expected_cash'] ?? 0));
        $sumOverage = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['effective_diff_overage'] ?? 0));
        $sumShortage = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['effective_diff_shortage'] ?? 0));
        $sumShortageRaw = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['diff_shortage'] ?? 0));
        $sumCarried = (int)$summaryRows->sum(fn($r) => (int)($r['finance']['carry_to_next_opening'] ?? 0));
        $reconciledCount = (int)$summaryRows->filter(fn($r) => !empty($r['finance']['is_effectively_reconciled']) || !empty($r['finance']['shortage_carried_to_next']))->count();

        return response()->json([
            'data' => [
                'items' => $p->items(),
                'pagination' => [
                    'current_page' => $p->currentPage(),
                    'last_page' => $p->lastPage(),
                    'per_page' => $p->perPage(),
                    'total' => $p->total(),
                ],
                'summary' => [
                    'shifts_count' => $summaryCount,
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
                    'unreconciled_count' => $summaryCount - $reconciledCount,
                ],
            ],
        ]);
    }

    private function exportLabels(string $lang): array
    {
        $dict = [
            'uz' => [
                'dashboard' => 'Dashboard',
                'sheetShifts' => 'Smena Tarixi',
                'sheetPeaks' => 'Top Choqqilar',
                'title' => 'Smena tarixi eksporti',
                'period' => 'Davr',
                'generated' => 'Yaratilgan',
                'kpi' => 'Asosiy KPI',
                'insights' => 'AI uslubidagi tahliliy signal',
                'topShortage' => 'Eng katta kamomadli smenalar',
                'id' => 'ID',
                'closedAt' => 'Yopilgan',
                'operator' => 'Operator',
                'expected' => 'Expected',
                'closing' => 'Closing',
                'diff' => 'Diff',
                'status' => 'Status',
                'signals' => 'Signal',
                'panelInsights' => 'Risk signallari',
                'panelTopGaps' => 'Top kamomad smenalar',
                'carriedAmount' => 'Otkazilgan',
                'unresolvedAmount' => 'Yopilmagan',
                'kpiShifts' => 'Smenalar soni',
                'kpiCashIn' => 'Naqd kirim',
                'kpiCardIn' => 'Karta kirim',
                'kpiReturns' => 'Qaytarishlar jami',
                'kpiExpenses' => 'Xarajatlar jami',
                'kpiExpected' => 'Expected summa',
                'kpiClosing' => 'Closing summa',
                'kpiNet' => 'Net cash',
                'kpiOver' => 'Overage summa',
                'kpiShort' => 'Shortage summa',
                'kpiRecon' => 'Reconciled ulushi',
                'matched' => 'Mos',
                'carried' => 'Otkazilgan',
                'partial' => 'Qisman otkazilgan',
                'overage' => 'Ortiqcha',
                'shortage' => 'Kamomad',
                'shiftSignalsShort' => 'Kutilgandan kam: {amount}',
                'shiftSignalsOver' => 'Kutilgandan kop: {amount}',
                'shiftSignalsExpense' => 'Xarajat bosimi: {ratio}%',
                'shiftSignalsReturns' => 'Naqd qaytarish ulushi: {ratio}%',
                'shiftSignalsOps' => 'Naqd tranzaksiya soni yuqori: {count}',
                'shiftSignalsOk' => 'Kuchli risk signali topilmadi',
                'insightShortage' => 'Umumiy kamomad: {value}. Kassa intizomini tekshirish tavsiya etiladi.',
                'insightReconLow' => 'Reconciled ulushi past: {value}%. Shift yopish jarayonini qayta koring.',
                'insightExpenseHigh' => 'Xarajat bosimi yuqori: {value}% (naqd kirimga nisbatan).',
                'insightReturnsHigh' => 'Naqd qaytarish ulushi yuqori: {value}% (naqd kirimga nisbatan).',
                'insightStable' => 'Korsatkichlar barqaror: keskin risk aniqlanmadi.',
                'sShiftId' => 'Shift ID',
                'sOpenedAt' => 'Opened at',
                'sClosedAt' => 'Closed at',
                'sOpenedBy' => 'Opened by',
                'sClosedBy' => 'Closed by',
                'sOpeningCash' => 'Opening cash',
                'sCashIn' => 'Cash in',
                'sCardIn' => 'Card in',
                'sGrossIn' => 'Gross in',
                'sReturnsCash' => 'Returns cash',
                'sReturnsTotal' => 'Returns total',
                'sExpenses' => 'Expenses',
                'sExpected' => 'Expected',
                'sClosing' => 'Closing',
                'sDiff' => 'Diff',
                'sNet' => 'Net',
                'sStatus' => 'Status',
                'sSignal' => 'Signal',
                'pType' => 'Turi',
                'pShiftId' => 'Shift ID',
                'pWhen' => 'Vaqt',
                'pName' => 'Nomi',
                'pCategory' => 'Kategoriya',
                'pClientId' => 'Client ID',
                'pAmount' => 'Summa',
            ],
            'ru' => [
                'dashboard' => 'Dashboard',
                'sheetShifts' => 'Istoriya Smen',
                'sheetPeaks' => 'Top Piki',
                'title' => 'Export istorii smen',
                'period' => 'Period',
                'generated' => 'Sformirovano',
                'kpi' => 'Klyuchevye KPI',
                'insights' => 'Analiticheskie signaly (AI-style)',
                'topShortage' => 'Smeny s maksimalnoy nedostachey',
                'id' => 'ID',
                'closedAt' => 'Zakryta',
                'operator' => 'Operator',
                'expected' => 'Expected',
                'closing' => 'Closing',
                'diff' => 'Diff',
                'status' => 'Status',
                'signals' => 'Signal',
                'panelInsights' => 'Signaly riska',
                'panelTopGaps' => 'Top smeny s nedostachey',
                'carriedAmount' => 'Pereneseno',
                'unresolvedAmount' => 'Ne zakryto',
                'kpiShifts' => 'Kolichestvo smen',
                'kpiCashIn' => 'Nalichnyy prihod',
                'kpiCardIn' => 'Kartochniy prihod',
                'kpiReturns' => 'Vozvraty itogo',
                'kpiExpenses' => 'Rashody itogo',
                'kpiExpected' => 'Expected summa',
                'kpiClosing' => 'Closing summa',
                'kpiNet' => 'Net cash',
                'kpiOver' => 'Overage summa',
                'kpiShort' => 'Shortage summa',
                'kpiRecon' => 'Dolya reconciled',
                'matched' => 'Skhoditsya',
                'carried' => 'Pereneseno',
                'partial' => 'Chastichno pereneseno',
                'overage' => 'Izlishek',
                'shortage' => 'Nedostacha',
                'shiftSignalsShort' => 'Nizhe ozhidaemogo: {amount}',
                'shiftSignalsOver' => 'Vyshe ozhidaemogo: {amount}',
                'shiftSignalsExpense' => 'Davlenie rashodov: {ratio}%',
                'shiftSignalsReturns' => 'Dolya cash-vozvratov: {ratio}%',
                'shiftSignalsOps' => 'Mnogo nalichnyh operaciy: {count}',
                'shiftSignalsOk' => 'Silnyh risk-signalov net',
                'insightShortage' => 'Obshchaya nedostacha: {value}. Rekomenduetsya proverit kasovuyu disciplinu.',
                'insightReconLow' => 'Nizkaya dolya reconciled: {value}%. Pereproverte process zakrytiya smeny.',
                'insightExpenseHigh' => 'Vysokoe davlenie rashodov: {value}% ot cash in.',
                'insightReturnsHigh' => 'Vysokaya dolya cash-vozvratov: {value}% ot cash in.',
                'insightStable' => 'Pokazateli stabilny, rezkih riskov ne naydeno.',
                'sShiftId' => 'Shift ID',
                'sOpenedAt' => 'Opened at',
                'sClosedAt' => 'Closed at',
                'sOpenedBy' => 'Opened by',
                'sClosedBy' => 'Closed by',
                'sOpeningCash' => 'Opening cash',
                'sCashIn' => 'Cash in',
                'sCardIn' => 'Card in',
                'sGrossIn' => 'Gross in',
                'sReturnsCash' => 'Returns cash',
                'sReturnsTotal' => 'Returns total',
                'sExpenses' => 'Expenses',
                'sExpected' => 'Expected',
                'sClosing' => 'Closing',
                'sDiff' => 'Diff',
                'sNet' => 'Net',
                'sStatus' => 'Status',
                'sSignal' => 'Signal',
                'pType' => 'Tip',
                'pShiftId' => 'Shift ID',
                'pWhen' => 'Vremya',
                'pName' => 'Nazvanie',
                'pCategory' => 'Kategoriya',
                'pClientId' => 'Client ID',
                'pAmount' => 'Summa',
            ],
            'en' => [
                'dashboard' => 'Dashboard',
                'sheetShifts' => 'Shift History',
                'sheetPeaks' => 'Top Peaks',
                'title' => 'Shift History Export',
                'period' => 'Period',
                'generated' => 'Generated at',
                'kpi' => 'Core KPI',
                'insights' => 'AI-style analytical signals',
                'topShortage' => 'Highest-shortage shifts',
                'id' => 'ID',
                'closedAt' => 'Closed at',
                'operator' => 'Operator',
                'expected' => 'Expected',
                'closing' => 'Closing',
                'diff' => 'Diff',
                'status' => 'Status',
                'signals' => 'Signal',
                'panelInsights' => 'Risk Signals',
                'panelTopGaps' => 'Top Shortage Shifts',
                'carriedAmount' => 'Carried',
                'unresolvedAmount' => 'Unresolved',
                'kpiShifts' => 'Shifts count',
                'kpiCashIn' => 'Cash in',
                'kpiCardIn' => 'Card in',
                'kpiReturns' => 'Returns total',
                'kpiExpenses' => 'Expenses total',
                'kpiExpected' => 'Expected sum',
                'kpiClosing' => 'Closing sum',
                'kpiNet' => 'Net cash',
                'kpiOver' => 'Overage sum',
                'kpiShort' => 'Shortage sum',
                'kpiRecon' => 'Reconciled ratio',
                'matched' => 'Matched',
                'carried' => 'Carried',
                'partial' => 'Partially carried',
                'overage' => 'Overage',
                'shortage' => 'Shortage',
                'shiftSignalsShort' => 'Below expected: {amount}',
                'shiftSignalsOver' => 'Above expected: {amount}',
                'shiftSignalsExpense' => 'Expense pressure: {ratio}%',
                'shiftSignalsReturns' => 'Cash return share: {ratio}%',
                'shiftSignalsOps' => 'High cash ops count: {count}',
                'shiftSignalsOk' => 'No strong risk signals',
                'insightShortage' => 'Total shortage: {value}. Cash discipline review is recommended.',
                'insightReconLow' => 'Low reconciled ratio: {value}%. Review shift-closing workflow.',
                'insightExpenseHigh' => 'High expense pressure: {value}% of cash in.',
                'insightReturnsHigh' => 'High cash-return share: {value}% of cash in.',
                'insightStable' => 'Metrics look stable, no sharp risk signal detected.',
                'sShiftId' => 'Shift ID',
                'sOpenedAt' => 'Opened at',
                'sClosedAt' => 'Closed at',
                'sOpenedBy' => 'Opened by',
                'sClosedBy' => 'Closed by',
                'sOpeningCash' => 'Opening cash',
                'sCashIn' => 'Cash in',
                'sCardIn' => 'Card in',
                'sGrossIn' => 'Gross in',
                'sReturnsCash' => 'Returns cash',
                'sReturnsTotal' => 'Returns total',
                'sExpenses' => 'Expenses',
                'sExpected' => 'Expected',
                'sClosing' => 'Closing',
                'sDiff' => 'Diff',
                'sNet' => 'Net',
                'sStatus' => 'Status',
                'sSignal' => 'Signal',
                'pType' => 'Type',
                'pShiftId' => 'Shift ID',
                'pWhen' => 'When',
                'pName' => 'Name',
                'pCategory' => 'Category',
                'pClientId' => 'Client ID',
                'pAmount' => 'Amount',
            ],
        ];

        return $dict[$lang] ?? $dict['uz'];
    }

    private function exportText(string $lang, string $key, array $params = []): string
    {
        $labels = $this->exportLabels($lang);
        $text = $labels[$key] ?? $this->exportLabels('uz')[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
        return $text;
    }

    private function shiftSignals(array $finance, string $lang): array
    {
        $signals = [];
        $cashIn = max(1, (int)($finance['cash_in'] ?? 0));
        $expenseRatio = (int)round(((int)($finance['expenses_cash'] ?? 0) / $cashIn) * 100);
        $returnsRatio = (int)round(((int)($finance['returns_cash'] ?? 0) / $cashIn) * 100);
        $diff = (int)($finance['effective_diff'] ?? ($finance['diff'] ?? 0));

        if ($diff < 0) {
            $signals[] = $this->exportText($lang, 'shiftSignalsShort', ['amount' => number_format(abs($diff), 0, '.', ',')]);
        } elseif ($diff > 0) {
            $signals[] = $this->exportText($lang, 'shiftSignalsOver', ['amount' => number_format(abs($diff), 0, '.', ',')]);
        }
        if ($expenseRatio >= 35) {
            $signals[] = $this->exportText($lang, 'shiftSignalsExpense', ['ratio' => $expenseRatio]);
        }
        if ($returnsRatio >= 20) {
            $signals[] = $this->exportText($lang, 'shiftSignalsReturns', ['ratio' => $returnsRatio]);
        }
        if ((int)($finance['tx_count'] ?? 0) >= 80) {
            $signals[] = $this->exportText($lang, 'shiftSignalsOps', ['count' => (int)($finance['tx_count'] ?? 0)]);
        }

        return $signals ?: [$this->exportText($lang, 'shiftSignalsOk')];
    }

    private function xlsEsc($value): string
    {
        return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xlsCell($value, string $type = 'String', ?string $style = null): string
    {
        $styleAttr = $style ? ' ss:StyleID="' . $style . '"' : '';

        if ($value === null || $value === '') {
            return "<Cell{$styleAttr}/>";
        }

        if ($type === 'Number') {
            $num = is_numeric($value) ? (string)(0 + $value) : '0';
            return "<Cell{$styleAttr}><Data ss:Type=\"Number\">{$num}</Data></Cell>";
        }

        return "<Cell{$styleAttr}><Data ss:Type=\"String\">" . $this->xlsEsc($value) . "</Data></Cell>";
    }

    private function xlsRow(array $cells): string
    {
        return '<Row>' . implode('', $cells) . '</Row>';
    }

    private function xlsxColName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $index = (int)(($index - 1) / 26);
        }
        return $name ?: 'A';
    }

    private function xlsxCellXml(int $colIndex, int $rowIndex, array $cell): string
    {
        $ref = $this->xlsxColName($colIndex) . $rowIndex;
        $style = isset($cell['s']) ? ' s="' . (int)$cell['s'] . '"' : '';
        $type = $cell['t'] ?? 's';
        $value = $cell['v'] ?? '';

        if ($type === 'f') {
            $formula = (string)($cell['f'] ?? '');
            $num = is_numeric($value) ? (string)(0 + $value) : '0';
            return '<c r="' . $ref . '"' . $style . '><f>' . $this->xlsEsc($formula) . '</f><v>' . $num . '</v></c>';
        }

        if ($type === 'n') {
            $num = is_numeric($value) ? (string)(0 + $value) : '0';
            return '<c r="' . $ref . '"' . $style . '><v>' . $num . '</v></c>';
        }

        $text = $this->xlsEsc((string)$value);
        return '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t>' . $text . '</t></is></c>';
    }

    private function xlsxSheetXml(array $rows, array $colWidths = [], ?string $drawingRelId = null, array $options = []): string
    {
        $freezeRows = max(0, (int)($options['freeze_rows'] ?? 0));
        $freezeCols = max(0, (int)($options['freeze_cols'] ?? 0));
        $autoFilter = (string)($options['auto_filter'] ?? '');
        $mergeCells = array_values(array_filter((array)($options['merge_cells'] ?? []), fn($r) => is_string($r) && $r !== ''));
        $rowHeights = (array)($options['row_heights'] ?? []);
        $hideGrid = !empty($options['hide_grid']);

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $sheetViewAttr = $hideGrid ? ' showGridLines="0"' : '';
        $xml[] = '<sheetViews><sheetView workbookViewId="0"' . $sheetViewAttr . '>';
        if ($freezeRows > 0 || $freezeCols > 0) {
            $xSplit = $freezeCols > 0 ? ' xSplit="' . $freezeCols . '"' : '';
            $ySplit = $freezeRows > 0 ? ' ySplit="' . $freezeRows . '"' : '';
            $activePane = 'bottomRight';
            if ($freezeCols > 0 && $freezeRows === 0) {
                $activePane = 'topRight';
            } elseif ($freezeCols === 0 && $freezeRows > 0) {
                $activePane = 'bottomLeft';
            }
            $topLeft = $this->xlsxColName($freezeCols + 1) . ($freezeRows + 1);
            $xml[] = '<pane' . $xSplit . $ySplit . ' topLeftCell="' . $topLeft . '" activePane="' . $activePane . '" state="frozen"/>';
            $xml[] = '<selection pane="' . $activePane . '" activeCell="' . $topLeft . '" sqref="' . $topLeft . '"/>';
        }
        $xml[] = '</sheetView></sheetViews>';
        $xml[] = '<sheetFormatPr defaultRowHeight="15"/>';

        if ($colWidths) {
            $xml[] = '<cols>';
            foreach ($colWidths as $idx => $width) {
                $col = (int)$idx + 1;
                $w = max(8, (float)$width);
                $xml[] = '<col min="' . $col . '" max="' . $col . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml[] = '</cols>';
        }

        $xml[] = '<sheetData>';
        foreach ($rows as $rowIdx => $cells) {
            $r = $rowIdx + 1;
            $height = isset($rowHeights[$r]) ? max(8, (float)$rowHeights[$r]) : null;
            $heightAttr = $height !== null ? ' ht="' . $height . '" customHeight="1"' : '';
            $xml[] = '<row r="' . $r . '"' . $heightAttr . '>';
            foreach ($cells as $colIdx => $cell) {
                $c = $colIdx + 1;
                $xml[] = $this->xlsxCellXml($c, $r, $cell);
            }
            $xml[] = '</row>';
        }
        $xml[] = '</sheetData>';

        if ($autoFilter !== '') {
            $xml[] = '<autoFilter ref="' . $this->xlsEsc($autoFilter) . '"/>';
        }

        if ($mergeCells) {
            $xml[] = '<mergeCells count="' . count($mergeCells) . '">';
            foreach ($mergeCells as $ref) {
                $xml[] = '<mergeCell ref="' . $this->xlsEsc($ref) . '"/>';
            }
            $xml[] = '</mergeCells>';
        }

        if ($drawingRelId) {
            $xml[] = '<drawing r:id="' . $drawingRelId . '"/>';
        }

        $xml[] = '</worksheet>';
        return implode('', $xml);
    }

    private function buildDashboardImage(array $metrics, array $labels): string
    {
        $w = 1800;
        $h = 980;
        $im = imagecreatetruecolor($w, $h);
        imageantialias($im, true);
        imagesavealpha($im, true);

        $bg = imagecolorallocate($im, 5, 10, 23);
        imagefill($im, 0, 0, $bg);

        // Deep gradient background
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $r = (int)round(4 + (20 - 4) * $t);
            $g = (int)round(9 + (26 - 9) * $t);
            $b = (int)round(20 + (44 - 20) * $t);
            $c = imagecolorallocate($im, $r, $g, $b);
            imageline($im, 0, $y, $w, $y, $c);
        }

        $cyan = imagecolorallocate($im, 80, 235, 255);
        $blue = imagecolorallocate($im, 82, 143, 255);
        $purple = imagecolorallocate($im, 154, 103, 255);
        $white = imagecolorallocate($im, 240, 248, 255);
        $muted = imagecolorallocate($im, 166, 186, 220);
        $line = imagecolorallocate($im, 52, 74, 112);
        $dim = imagecolorallocate($im, 30, 48, 82);
        $good = imagecolorallocate($im, 52, 211, 153);
        $bad = imagecolorallocate($im, 244, 63, 94);
        $warn = imagecolorallocate($im, 251, 191, 36);

        $font = null;
        foreach ([
            'C:\\Windows\\Fonts\\segoeui.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ] as $candidate) {
            if (@is_file($candidate)) {
                $font = $candidate;
                break;
            }
        }

        $drawText = function (int $x, int $y, string $text, int $size, int $color) use ($im, $font): void {
            if ($font) {
                imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
            } else {
                $f = $size >= 16 ? 5 : ($size >= 12 ? 4 : 3);
                imagestring($im, $f, $x, max(0, $y - 14), $text, $color);
            }
        };

        $drawGlowNode = function (int $x, int $y, int $coreR, int $coreColor, int $glowColor) use ($im): void {
            for ($r = $coreR + 36; $r >= $coreR + 2; $r -= 2) {
                $alpha = max(40, min(126, 126 - (int)(($coreR + 36 - $r) * 2.8)));
                $c = imagecolorallocatealpha($im, ($glowColor >> 16) & 0xFF, ($glowColor >> 8) & 0xFF, $glowColor & 0xFF, $alpha);
                imagefilledellipse($im, $x, $y, $r * 2, $r * 2, $c);
            }
            imagefilledellipse($im, $x, $y, ($coreR + 6) * 2, ($coreR + 6) * 2, imagecolorallocate($im, 16, 30, 56));
            imagefilledellipse($im, $x, $y, $coreR * 2, $coreR * 2, $coreColor);
            imagefilledellipse($im, $x, $y, (int)($coreR * 0.58) * 2, (int)($coreR * 0.58) * 2, imagecolorallocate($im, 10, 22, 44));
        };

        // Top headline
        $drawText(44, 62, $labels['title'] ?? 'Dashboard', 24, $white);
        $drawText(46, 96, ($labels['period'] ?? 'Period') . ': ' . ($metrics['period'] ?? 'all'), 12, $muted);
        $drawText(590, 96, ($labels['generated'] ?? 'Generated') . ': ' . ($metrics['generated'] ?? ''), 12, $muted);

        // Left network graph
        $nodes = [
            'a' => [170, 420, (string)($labels['kpiShifts'] ?? 'Shifts'), (string)($metrics['shifts'] ?? 0)],
            'b' => [430, 300, (string)($labels['kpiCashIn'] ?? 'Cash in'), number_format((int)($metrics['cash_in'] ?? 0), 0, '.', ' ')],
            'c' => [640, 420, (string)($labels['kpiNet'] ?? 'Net cash'), number_format((int)($metrics['net_cash'] ?? 0), 0, '.', ' ')],
            'd' => [430, 550, (string)($labels['kpiExpenses'] ?? 'Expenses'), number_format((int)($metrics['expenses'] ?? 0), 0, '.', ' ')],
            'e' => [870, 420, (string)($labels['kpiReturns'] ?? 'Returns'), number_format((int)($metrics['returns'] ?? 0), 0, '.', ' ')],
        ];
        $links = [['a', 'b'], ['a', 'd'], ['b', 'c'], ['d', 'c'], ['c', 'e'], ['b', 'd']];
        imagesetthickness($im, 2);
        foreach ($links as [$l, $r]) {
            imageline($im, $nodes[$l][0], $nodes[$l][1], $nodes[$r][0], $nodes[$r][1], $line);
        }

        foreach ($nodes as $key => $node) {
            [$nx, $ny, $caption, $value] = $node;
            $core = ($key === 'c' || $key === 'e') ? $purple : $blue;
            $drawGlowNode($nx, $ny, 20, $core, $core);
            $drawText($nx - 58, $ny + 52, $caption, 11, $muted);
            $drawText($nx - 44, $ny + 78, $value, 14, $white);
        }

        // Right radial dashboard
        $cx = 1400;
        $cy = 470;
        imagesetthickness($im, 2);
        for ($ring = 110; $ring <= 340; $ring += 55) {
            imagearc($im, $cx, $cy, $ring * 2, $ring * 2, 0, 360, $dim);
        }

        $pct = max(0, min(100, (int)round((float)($metrics['reconciled_pct'] ?? 0))));
        $start = -90;
        $end = (int)round($start + (360 * $pct / 100));

        imagesetthickness($im, 30);
        imagearc($im, $cx, $cy, 430, 430, 0, 360, imagecolorallocate($im, 33, 44, 70));
        imagesetthickness($im, 26);
        imagearc($im, $cx, $cy, 430, 430, $start, $end, $purple);

        imagefilledellipse($im, $cx, $cy, 290, 290, imagecolorallocate($im, 8, 16, 33));
        imagefilledellipse($im, $cx, $cy, 254, 254, imagecolorallocate($im, 12, 22, 42));

        $drawText($cx - 78, $cy - 22, (string)$pct . '%', 30, $white);
        $drawText($cx - 98, $cy + 22, (string)($labels['kpiRecon'] ?? 'Reconciled'), 11, $muted);
        $drawText($cx - 58, $cy + 52, number_format((int)($metrics['net_cash'] ?? 0), 0, '.', ' '), 17, $cyan);

        $shortage = (int)($metrics['shortage'] ?? 0);
        $carry = (int)($metrics['carried'] ?? 0);
        $unresolved = max(0, $shortage - $carry);
        $drawText($cx - 264, $cy + 204, ($labels['carriedAmount'] ?? 'Carried') . ': ' . number_format($carry, 0, '.', ' '), 12, $good);
        $drawText($cx + 40, $cy + 204, ($labels['unresolvedAmount'] ?? 'Unresolved') . ': ' . number_format($unresolved, 0, '.', ' '), 12, $bad);

        // Bottom chips
        $chips = [
            [40, 780, ($labels['kpiOver'] ?? 'Overage'), (int)($metrics['overage'] ?? 0), $good],
            [370, 780, ($labels['kpiShort'] ?? 'Shortage'), $shortage, $bad],
            [700, 780, ($labels['kpiExpected'] ?? 'Expected'), (int)($metrics['expected'] ?? 0), $warn],
        ];
        foreach ($chips as [$x, $y, $t, $v, $accent]) {
            imagefilledrectangle($im, $x, $y, $x + 300, $y + 120, imagecolorallocate($im, 13, 28, 53));
            imagerectangle($im, $x, $y, $x + 300, $y + 120, $line);
            imagefilledrectangle($im, $x, $y, $x + 300, $y + 6, $accent);
            $drawText($x + 16, $y + 40, (string)$t, 11, $muted);
            $drawText($x + 16, $y + 84, number_format((int)$v, 0, '.', ' '), 18, $white);
        }

        ob_start();
        imagepng($im, null, 6);
        $png = (string)ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    // GET /api/shifts/history/export?from=YYYY-MM-DD&to=YYYY-MM-DD&lang=uz
    public function export(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'lang' => ['nullable', 'in:uz,ru,en'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:5000'],
        ]);

        $lang = (string)($data['lang'] ?? 'uz');
        $limit = (int)($data['limit'] ?? 2000);
        $labels = $this->exportLabels($lang);

        $q = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('closed_at')
            ->addSelect([
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
            ])
            ->with([
                'openedBy:id,login,name,role',
                'closedBy:id,login,name,role',
            ])
            ->withSum('expenses as expenses_cash_total', 'amount');

        if (!empty($data['from']) && !empty($data['to'])) {
            $from = Carbon::parse($data['from'])->startOfDay();
            $to = Carbon::parse($data['to'])->endOfDay();
            $q->whereBetween('closed_at', [$from, $to]);
        }

        $items = $q->orderByDesc('closed_at')->limit($limit)->get();
        $shiftIds = $items->pluck('id')->map(fn($id) => (int)$id)->all();
        $financeMap = $this->loadFinanceMap($tenantId, $shiftIds);

        $rows = [];
        foreach ($items as $shift) {
            $bucket = $financeMap[(int)$shift->id] ?? ['tx' => null, 'returns' => null, 'expenses' => null];
            $finance = $this->buildShiftFinance($shift, $bucket['tx'], $bucket['returns'], $bucket['expenses']);
            $status = $this->resolveReconcileStatus($finance);
            $signals = $this->shiftSignals($finance, $lang);
            $rows[] = [
                'shift' => $shift,
                'finance' => $finance,
                'status' => $status,
                'status_label' => $labels[$status] ?? $status,
                'signals' => implode('; ', $signals),
            ];
        }

        $sumCashIn = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['cash_in'] ?? 0));
        $sumCardIn = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['card_in'] ?? 0));
        $sumExpenses = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['expenses_cash'] ?? 0));
        $sumReturnsCash = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['returns_cash'] ?? 0));
        $sumReturnsTotal = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['returns_total'] ?? 0));
        $sumClosing = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['closing_cash'] ?? 0));
        $sumExpected = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['expected_cash'] ?? 0));
        $sumNetCash = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['net_cash'] ?? 0));
        $sumOverage = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['effective_diff_overage'] ?? 0));
        $sumShortage = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['effective_diff_shortage'] ?? 0));
        $reconciledCount = (int)collect($rows)->filter(fn($r) => !empty($r['finance']['is_effectively_reconciled']) || !empty($r['finance']['shortage_carried_to_next']))->count();
        $totalCount = max(1, count($rows));
        $reconciledPct = round(($reconciledCount / $totalCount) * 100, 1);

        $insights = [];
        if ($sumShortage > 0) {
            $insights[] = $this->exportText($lang, 'insightShortage', ['value' => number_format($sumShortage, 0, '.', ',')]);
        }
        if ($reconciledPct < 80) {
            $insights[] = $this->exportText($lang, 'insightReconLow', ['value' => $reconciledPct]);
        }
        if ($sumCashIn > 0) {
            $expensePressure = round(($sumExpenses / $sumCashIn) * 100, 1);
            $returnsPressure = round(($sumReturnsCash / $sumCashIn) * 100, 1);
            if ($expensePressure >= 35) {
                $insights[] = $this->exportText($lang, 'insightExpenseHigh', ['value' => $expensePressure]);
            }
            if ($returnsPressure >= 20) {
                $insights[] = $this->exportText($lang, 'insightReturnsHigh', ['value' => $returnsPressure]);
            }
        }
        if (!$insights) {
            $insights[] = $this->exportText($lang, 'insightStable');
        }

        $topShortages = collect($rows)
            ->filter(fn($r) => (int)($r['finance']['effective_diff'] ?? 0) < 0)
            ->sortBy(fn($r) => (int)$r['finance']['effective_diff'])
            ->take(20)
            ->values();

        $peakExpenses = [];
        $peakReturns = [];
        if ($shiftIds) {
            $peakExpenses = ShiftExpense::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('shift_id', $shiftIds)
                ->orderByRaw('ABS(amount) DESC')
                ->limit(30)
                ->get(['shift_id', 'spent_at', 'title', 'category', 'amount'])
                ->map(fn($e) => [
                    'shift_id' => (int)$e->shift_id,
                    'at' => (string)($e->spent_at ?? ''),
                    'name' => (string)($e->title ?? ''),
                    'category' => (string)($e->category ?? ''),
                    'amount' => abs((int)$e->amount),
                ])->all();

            $peakReturns = ReturnRecord::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('shift_id', $shiftIds)
                ->where('payment_method', 'cash')
                ->orderByRaw('ABS(amount) DESC')
                ->limit(30)
                ->get(['shift_id', 'created_at', 'client_id', 'amount'])
                ->map(fn($r) => [
                    'shift_id' => (int)$r->shift_id,
                    'at' => (string)($r->created_at ?? ''),
                    'client_id' => (int)($r->client_id ?? 0),
                    'amount' => abs((int)$r->amount),
                ])->all();
        }

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<?mso-application progid="Excel.Sheet"?>';
        $xml[] = '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">';
        $xml[] = '<Styles>';
        $xml[] = '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10"/><Interior/><NumberFormat/><Protection/></Style>';
        $xml[] = '<Style ss:ID="Title"><Font ss:FontName="Calibri" ss:Size="15" ss:Bold="1"/><Interior ss:Color="#DCEEFF" ss:Pattern="Solid"/></Style>';
        $xml[] = '<Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#E9EFF7" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>';
        $xml[] = '<Style ss:ID="Currency"><NumberFormat ss:Format="#,##0"/></Style>';
        $xml[] = '<Style ss:ID="Pct"><NumberFormat ss:Format="0.0"/></Style>';
        $xml[] = '<Style ss:ID="Bad"><Font ss:Color="#9C0006" ss:Bold="1"/><Interior ss:Color="#FFC7CE" ss:Pattern="Solid"/></Style>';
        $xml[] = '<Style ss:ID="Good"><Font ss:Color="#006100" ss:Bold="1"/><Interior ss:Color="#C6EFCE" ss:Pattern="Solid"/></Style>';
        $xml[] = '<Style ss:ID="Warn"><Font ss:Color="#7F6000" ss:Bold="1"/><Interior ss:Color="#FFEB9C" ss:Pattern="Solid"/></Style>';
        $xml[] = '</Styles>';

        $xml[] = '<Worksheet ss:Name="' . $this->xlsEsc($labels['dashboard']) . '"><Table>';
        $xml[] = $this->xlsRow([$this->xlsCell($labels['title'], 'String', 'Title')]);
        $periodLabel = (!empty($data['from']) && !empty($data['to'])) ? ($data['from'] . ' -> ' . $data['to']) : 'all';
        $xml[] = $this->xlsRow([$this->xlsCell($labels['period'], 'String', 'Header'), $this->xlsCell($periodLabel)]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['generated'], 'String', 'Header'), $this->xlsCell(now()->format('Y-m-d H:i:s'))]);
        $xml[] = $this->xlsRow([$this->xlsCell('')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpi'], 'String', 'Header')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiShifts']), $this->xlsCell(count($rows), 'Number')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiCashIn']), $this->xlsCell($sumCashIn, 'Number', 'Currency')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiCardIn']), $this->xlsCell($sumCardIn, 'Number', 'Currency')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiReturns']), $this->xlsCell($sumReturnsTotal, 'Number', 'Currency')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiExpenses']), $this->xlsCell($sumExpenses, 'Number', 'Currency')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiExpected']), $this->xlsCell($sumExpected, 'Number', 'Currency')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiClosing']), $this->xlsCell($sumClosing, 'Number', 'Currency')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiNet']), $this->xlsCell($sumNetCash, 'Number', 'Currency')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiOver']), $this->xlsCell($sumOverage, 'Number', 'Currency')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiShort']), $this->xlsCell($sumShortage, 'Number', 'Currency')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['kpiRecon']), $this->xlsCell($reconciledPct, 'Number', 'Pct')]);
        $xml[] = $this->xlsRow([$this->xlsCell('')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['insights'], 'String', 'Header')]);
        foreach ($insights as $insight) {
            $xml[] = $this->xlsRow([$this->xlsCell($insight)]);
        }
        $xml[] = $this->xlsRow([$this->xlsCell('')]);
        $xml[] = $this->xlsRow([$this->xlsCell($labels['topShortage'], 'String', 'Header')]);
        $xml[] = $this->xlsRow([
            $this->xlsCell($labels['id'], 'String', 'Header'),
            $this->xlsCell($labels['closedAt'], 'String', 'Header'),
            $this->xlsCell($labels['operator'], 'String', 'Header'),
            $this->xlsCell($labels['expected'], 'String', 'Header'),
            $this->xlsCell($labels['closing'], 'String', 'Header'),
            $this->xlsCell($labels['diff'], 'String', 'Header'),
            $this->xlsCell($labels['signals'], 'String', 'Header'),
        ]);
        foreach ($topShortages as $row) {
            $shift = $row['shift'];
            $f = $row['finance'];
            $xml[] = $this->xlsRow([
                $this->xlsCell($shift->id, 'Number'),
                $this->xlsCell((string)$shift->closed_at),
                $this->xlsCell((string)($shift->closedBy->name ?? $shift->closedBy->login ?? '-')),
                $this->xlsCell((int)$f['expected_cash'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['closing_cash'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['diff'], 'Number', ((int)$f['diff'] < 0 ? 'Bad' : 'Warn')),
                $this->xlsCell($row['signals']),
            ]);
        }
        $xml[] = '</Table></Worksheet>';

        $xml[] = '<Worksheet ss:Name="' . $this->xlsEsc($labels['sheetShifts']) . '"><Table>';
        $xml[] = $this->xlsRow([
            $this->xlsCell($labels['sShiftId'], 'String', 'Header'),
            $this->xlsCell($labels['sOpenedAt'], 'String', 'Header'),
            $this->xlsCell($labels['sClosedAt'], 'String', 'Header'),
            $this->xlsCell($labels['sOpenedBy'], 'String', 'Header'),
            $this->xlsCell($labels['sClosedBy'], 'String', 'Header'),
            $this->xlsCell($labels['sOpeningCash'], 'String', 'Header'),
            $this->xlsCell($labels['sCashIn'], 'String', 'Header'),
            $this->xlsCell($labels['sCardIn'], 'String', 'Header'),
            $this->xlsCell($labels['sGrossIn'], 'String', 'Header'),
            $this->xlsCell($labels['sReturnsCash'], 'String', 'Header'),
            $this->xlsCell($labels['sReturnsTotal'], 'String', 'Header'),
            $this->xlsCell($labels['sExpenses'], 'String', 'Header'),
            $this->xlsCell($labels['sExpected'], 'String', 'Header'),
            $this->xlsCell($labels['sClosing'], 'String', 'Header'),
            $this->xlsCell($labels['sDiff'], 'String', 'Header'),
            $this->xlsCell($labels['sNet'], 'String', 'Header'),
            $this->xlsCell($labels['sStatus'], 'String', 'Header'),
            $this->xlsCell($labels['sSignal'], 'String', 'Header'),
        ]);
        foreach ($rows as $row) {
            $shift = $row['shift'];
            $f = $row['finance'];
            $xml[] = $this->xlsRow([
                $this->xlsCell($shift->id, 'Number'),
                $this->xlsCell((string)$shift->opened_at),
                $this->xlsCell((string)$shift->closed_at),
                $this->xlsCell((string)($shift->openedBy->name ?? $shift->openedBy->login ?? '-')),
                $this->xlsCell((string)($shift->closedBy->name ?? $shift->closedBy->login ?? '-')),
                $this->xlsCell((int)$f['opening_cash'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['cash_in'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['card_in'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['gross_in'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['returns_cash'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['returns_total'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['expenses_cash'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['expected_cash'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['closing_cash'], 'Number', 'Currency'),
                $this->xlsCell((int)$f['diff'], 'Number', ((int)$f['diff'] === 0 ? 'Good' : ((int)$f['diff'] < 0 ? 'Bad' : 'Warn'))),
                $this->xlsCell((int)$f['net_cash'], 'Number', 'Currency'),
                $this->xlsCell($row['status_label']),
                $this->xlsCell($row['signals']),
            ]);
        }
        $xml[] = '</Table></Worksheet>';

        $xml[] = '<Worksheet ss:Name="' . $this->xlsEsc($labels['sheetPeaks']) . '"><Table>';
        $xml[] = $this->xlsRow([
            $this->xlsCell($labels['pType'], 'String', 'Header'),
            $this->xlsCell($labels['pShiftId'], 'String', 'Header'),
            $this->xlsCell($labels['pWhen'], 'String', 'Header'),
            $this->xlsCell($labels['pName'], 'String', 'Header'),
            $this->xlsCell($labels['pCategory'], 'String', 'Header'),
            $this->xlsCell($labels['pClientId'], 'String', 'Header'),
            $this->xlsCell($labels['pAmount'], 'String', 'Header'),
        ]);
        foreach ($peakExpenses as $exp) {
            $xml[] = $this->xlsRow([
                $this->xlsCell('expense'),
                $this->xlsCell($exp['shift_id'], 'Number'),
                $this->xlsCell($exp['at']),
                $this->xlsCell($exp['name']),
                $this->xlsCell($exp['category']),
                $this->xlsCell(''),
                $this->xlsCell($exp['amount'], 'Number', 'Currency'),
            ]);
        }
        foreach ($peakReturns as $ret) {
            $xml[] = $this->xlsRow([
                $this->xlsCell('cash_return'),
                $this->xlsCell($ret['shift_id'], 'Number'),
                $this->xlsCell($ret['at']),
                $this->xlsCell(''),
                $this->xlsCell(''),
                $this->xlsCell($ret['client_id'], 'Number'),
                $this->xlsCell($ret['amount'], 'Number', 'Currency'),
            ]);
        }
        $xml[] = '</Table></Worksheet>';
        $xml[] = '</Workbook>';

        $filename = 'shift-history-' . now()->format('Ymd-His') . '.xls';

        return response(implode('', $xml), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    // GET /api/shifts/history/export-xlsx?from=YYYY-MM-DD&to=YYYY-MM-DD&lang=uz
    public function exportXlsx(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'lang' => ['nullable', 'in:uz,ru,en'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:5000'],
        ]);

        $lang = (string)($data['lang'] ?? 'uz');
        $limit = (int)($data['limit'] ?? 2000);
        $labels = $this->exportLabels($lang);

        $q = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('closed_at')
            ->addSelect([
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
            ])
            ->with([
                'openedBy:id,login,name,role',
                'closedBy:id,login,name,role',
            ])
            ->withSum('expenses as expenses_cash_total', 'amount');

        if (!empty($data['from']) && !empty($data['to'])) {
            $from = Carbon::parse($data['from'])->startOfDay();
            $to = Carbon::parse($data['to'])->endOfDay();
            $q->whereBetween('closed_at', [$from, $to]);
        }

        $items = $q->orderByDesc('closed_at')->limit($limit)->get();
        $shiftIds = $items->pluck('id')->map(fn($id) => (int)$id)->all();
        $financeMap = $this->loadFinanceMap($tenantId, $shiftIds);

        $rows = [];
        foreach ($items as $shift) {
            $bucket = $financeMap[(int)$shift->id] ?? ['tx' => null, 'returns' => null, 'expenses' => null];
            $finance = $this->buildShiftFinance($shift, $bucket['tx'], $bucket['returns'], $bucket['expenses']);
            $status = $this->resolveReconcileStatus($finance);
            $signals = $this->shiftSignals($finance, $lang);
            $rows[] = [
                'shift' => $shift,
                'finance' => $finance,
                'status' => $status,
                'status_label' => $labels[$status] ?? $status,
                'signals' => implode('; ', $signals),
            ];
        }

        $sumCashIn = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['cash_in'] ?? 0));
        $sumCardIn = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['card_in'] ?? 0));
        $sumExpenses = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['expenses_cash'] ?? 0));
        $sumReturnsCash = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['returns_cash'] ?? 0));
        $sumReturnsTotal = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['returns_total'] ?? 0));
        $sumClosing = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['closing_cash'] ?? 0));
        $sumExpected = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['expected_cash'] ?? 0));
        $sumNetCash = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['net_cash'] ?? 0));
        $sumOverage = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['effective_diff_overage'] ?? 0));
        $sumShortage = (int)collect($rows)->sum(fn($r) => (int)($r['finance']['effective_diff_shortage'] ?? 0));
        $reconciledCount = (int)collect($rows)->filter(fn($r) => !empty($r['finance']['is_effectively_reconciled']) || !empty($r['finance']['shortage_carried_to_next']))->count();
        $totalCount = max(1, count($rows));
        $reconciledPct = round(($reconciledCount / $totalCount) * 100, 1);

        $insights = [];
        if ($sumShortage > 0) {
            $insights[] = $this->exportText($lang, 'insightShortage', ['value' => number_format($sumShortage, 0, '.', ',')]);
        }
        if ($reconciledPct < 80) {
            $insights[] = $this->exportText($lang, 'insightReconLow', ['value' => $reconciledPct]);
        }
        if ($sumCashIn > 0) {
            $expensePressure = round(($sumExpenses / $sumCashIn) * 100, 1);
            $returnsPressure = round(($sumReturnsCash / $sumCashIn) * 100, 1);
            if ($expensePressure >= 35) {
                $insights[] = $this->exportText($lang, 'insightExpenseHigh', ['value' => $expensePressure]);
            }
            if ($returnsPressure >= 20) {
                $insights[] = $this->exportText($lang, 'insightReturnsHigh', ['value' => $returnsPressure]);
            }
        }
        if (!$insights) {
            $insights[] = $this->exportText($lang, 'insightStable');
        }

        $kpiChartRows = [
            [$labels['kpiShifts'], count($rows)],
            [$labels['kpiCashIn'], $sumCashIn],
            [$labels['kpiCardIn'], $sumCardIn],
            [$labels['kpiReturns'], $sumReturnsTotal],
            [$labels['kpiExpenses'], $sumExpenses],
            [$labels['kpiExpected'], $sumExpected],
            [$labels['kpiClosing'], $sumClosing],
            [$labels['kpiNet'], $sumNetCash],
            [$labels['kpiOver'], $sumOverage],
            [$labels['kpiShort'], $sumShortage],
        ];

        $topShortages = collect($rows)
            ->filter(fn($r) => (int)($r['finance']['effective_diff'] ?? 0) < 0)
            ->sortBy(fn($r) => (int)($r['finance']['effective_diff'] ?? 0))
            ->take(5)
            ->values()
            ->all();

        $fillRow = static function (int $cols, int $style = 3): array {
            $cells = [];
            for ($i = 0; $i < $cols; $i++) {
                $cells[] = ['v' => '', 's' => $style];
            }
            return $cells;
        };

        $buildCardLabelRow = static function (array $cards): array {
            $row = [];
            foreach ($cards as $card) {
                [$label, , $labelStyle] = $card;
                $row[] = ['v' => $label, 's' => $labelStyle];
                $row[] = ['v' => '', 's' => $labelStyle];
                $row[] = ['v' => '', 's' => $labelStyle];
            }
            return $row;
        };

        $buildCardValueRow = static function (array $cards): array {
            $row = [];
            foreach ($cards as $card) {
                [, $value, , $valueStyle] = $card;
                $row[] = ['v' => $value, 't' => 'n', 's' => $valueStyle];
                $row[] = ['v' => '', 's' => $valueStyle];
                $row[] = ['v' => '', 's' => $valueStyle];
            }
            return $row;
        };

        $statusStyleOf = static function (string $status): int {
            return match ($status) {
                'matched' => 9,
                'carried' => 10,
                'partial' => 11,
                'overage' => 12,
                'shortage' => 13,
                default => 3,
            };
        };

        $dashboardRows = [];
        $titleRow = [['v' => $labels['title'], 's' => 1]];
        for ($i = 0; $i < 11; $i++) {
            $titleRow[] = ['v' => '', 's' => 1];
        }
        $dashboardRows[] = $titleRow;
        $dashboardRows[] = $fillRow(12, 1);

        $periodLabel = (!empty($data['from']) && !empty($data['to'])) ? ($data['from'] . ' -> ' . $data['to']) : 'all';
        $dashboardRows[] = [
            ['v' => $labels['period'], 's' => 14], ['v' => $periodLabel, 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3],
            ['v' => $labels['generated'], 's' => 14], ['v' => now()->format('Y-m-d H:i:s'), 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3],
            ['v' => $labels['kpiRecon'], 's' => 14], ['v' => $reconciledPct, 't' => 'n', 's' => 5], ['v' => '', 's' => 5], ['v' => '', 's' => 5],
        ];
        $dashboardRows[] = $fillRow(12, 3);

        $cardsRow1 = [
            [$labels['kpiShifts'], (int)count($rows), 15, 16],
            [$labels['kpiCashIn'], (int)$sumCashIn, 17, 18],
            [$labels['kpiCardIn'], (int)$sumCardIn, 19, 20],
            [$labels['kpiNet'], (int)$sumNetCash, 21, 22],
        ];
        $cardsRow2 = [
            [$labels['kpiReturns'], (int)$sumReturnsTotal, 17, 18],
            [$labels['kpiExpenses'], (int)$sumExpenses, 19, 20],
            [$labels['kpiExpected'], (int)$sumExpected, 21, 22],
            [$labels['kpiShort'], (int)$sumShortage, 15, 16],
        ];

        $dashboardRows[] = $buildCardLabelRow($cardsRow1); // row 5
        $dashboardRows[] = $buildCardValueRow($cardsRow1); // row 6
        $dashboardRows[] = $fillRow(12, 3); // row 7
        $dashboardRows[] = $fillRow(12, 3); // row 8
        $dashboardRows[] = $buildCardLabelRow($cardsRow2); // row 9
        $dashboardRows[] = $buildCardValueRow($cardsRow2); // row 10
        $dashboardRows[] = $fillRow(12, 3); // row 11

        $dashboardRows[] = [
            ['v' => $labels['panelInsights'], 's' => 14], ['v' => '', 's' => 14], ['v' => '', 's' => 14], ['v' => '', 's' => 14], ['v' => '', 's' => 14], ['v' => '', 's' => 14],
            ['v' => $labels['panelTopGaps'], 's' => 14], ['v' => '', 's' => 14], ['v' => '', 's' => 14], ['v' => '', 's' => 14], ['v' => '', 's' => 14], ['v' => '', 's' => 14],
        ]; // row 12

        for ($i = 0; $i < 6; $i++) {
            $insightText = (string)($insights[$i] ?? '');
            $left = [['v' => $insightText, 's' => 23], ['v' => '', 's' => 23], ['v' => '', 's' => 23], ['v' => '', 's' => 23], ['v' => '', 's' => 23], ['v' => '', 's' => 23]];

            if ($i === 0) {
                $right = [
                    ['v' => $labels['sShiftId'], 's' => 2],
                    ['v' => $labels['operator'], 's' => 2],
                    ['v' => $labels['sDiff'], 's' => 2],
                    ['v' => $labels['carriedAmount'], 's' => 2],
                    ['v' => $labels['unresolvedAmount'], 's' => 2],
                    ['v' => $labels['sStatus'], 's' => 2],
                ];
            } else {
                $row = $topShortages[$i - 1] ?? null;
                if ($row) {
                    $shift = $row['shift'];
                    $f = $row['finance'];
                    $status = (string)($row['status'] ?? 'shortage');
                    $right = [
                        ['v' => (int)$shift->id, 't' => 'n', 's' => 3],
                        ['v' => (string)($shift->closedBy->name ?? $shift->closedBy->login ?? $shift->openedBy->name ?? $shift->openedBy->login ?? '-'), 's' => 3],
                        ['v' => abs((int)($f['effective_diff'] ?? 0)), 't' => 'n', 's' => 6],
                        ['v' => (int)($f['carry_to_next_opening'] ?? 0), 't' => 'n', 's' => 4],
                        ['v' => (int)($f['shortage_unresolved'] ?? 0), 't' => 'n', 's' => 4],
                        ['v' => (string)($row['status_label'] ?? $status), 's' => $statusStyleOf($status)],
                    ];
                } else {
                    $right = $fillRow(6, 3);
                }
            }

            $dashboardRows[] = array_merge($left, $right);
        }

        while (count($dashboardRows) < 41) {
            $dashboardRows[] = $fillRow(12, 3);
        }

        $dashboardRows[] = array_merge([['v' => $labels['kpi'], 's' => 2], ['v' => 'Value', 's' => 2]], $fillRow(10, 3)); // row 41
        foreach ($kpiChartRows as $kpiRow) {
            $dashboardRows[] = array_merge([['v' => $kpiRow[0], 's' => 3], ['v' => $kpiRow[1], 't' => 'n', 's' => 4]], $fillRow(10, 3));
        }
        $dashboardRows[] = array_merge([['v' => $labels['kpiRecon'], 's' => 3], ['v' => $reconciledPct, 't' => 'n', 's' => 5]], $fillRow(10, 3));
        $dashboardRows[] = $fillRow(12, 3);
        $dashboardRows[] = array_merge([['v' => 'Reconciled', 's' => 3], ['v' => (int)$reconciledCount, 't' => 'n', 's' => 4]], $fillRow(10, 3));
        $dashboardRows[] = array_merge([['v' => 'Unreconciled', 's' => 3], ['v' => max(0, $totalCount - $reconciledCount), 't' => 'n', 's' => 4]], $fillRow(10, 3));

        $shiftRows = [];
        $shiftRows[] = [
            ['v' => $labels['sShiftId'], 's' => 2],
            ['v' => $labels['sOpenedAt'], 's' => 2],
            ['v' => $labels['sClosedAt'], 's' => 2],
            ['v' => $labels['sOpenedBy'], 's' => 2],
            ['v' => $labels['sClosedBy'], 's' => 2],
            ['v' => $labels['sOpeningCash'], 's' => 2],
            ['v' => $labels['sCashIn'], 's' => 2],
            ['v' => $labels['sCardIn'], 's' => 2],
            ['v' => $labels['sGrossIn'], 's' => 2],
            ['v' => $labels['sReturnsCash'], 's' => 2],
            ['v' => $labels['sReturnsTotal'], 's' => 2],
            ['v' => $labels['sExpenses'], 's' => 2],
            ['v' => $labels['sExpected'], 's' => 2],
            ['v' => $labels['sClosing'], 's' => 2],
            ['v' => $labels['sDiff'], 's' => 2],
            ['v' => $labels['sNet'], 's' => 2],
            ['v' => $labels['sStatus'], 's' => 2],
            ['v' => $labels['sSignal'], 's' => 2],
        ];

        foreach ($rows as $idx => $row) {
            $shift = $row['shift'];
            $f = $row['finance'];
            $diff = (int)($f['effective_diff'] ?? $f['diff'] ?? 0);
            $diffStyle = $diff === 0 ? 7 : ($diff < 0 ? 6 : 8);
            $textStyle = $idx % 2 === 0 ? 24 : 25;
            $moneyStyle = $idx % 2 === 0 ? 26 : 27;
            $statusStyle = match ((string)($row['status'] ?? '')) {
                'matched' => 9,
                'carried' => 10,
                'partial' => 11,
                'overage' => 12,
                'shortage' => 13,
                default => 3,
            };

            $shiftRows[] = [
                ['v' => (int)$shift->id, 't' => 'n', 's' => $textStyle],
                ['v' => (string)$shift->opened_at, 's' => $textStyle],
                ['v' => (string)$shift->closed_at, 's' => $textStyle],
                ['v' => (string)($shift->openedBy->name ?? $shift->openedBy->login ?? '-'), 's' => $textStyle],
                ['v' => (string)($shift->closedBy->name ?? $shift->closedBy->login ?? '-'), 's' => $textStyle],
                ['v' => (int)$f['opening_cash'], 't' => 'n', 's' => $moneyStyle],
                ['v' => (int)$f['cash_in'], 't' => 'n', 's' => $moneyStyle],
                ['v' => (int)$f['card_in'], 't' => 'n', 's' => $moneyStyle],
                ['v' => (int)$f['gross_in'], 't' => 'n', 's' => $moneyStyle],
                ['v' => (int)$f['returns_cash'], 't' => 'n', 's' => $moneyStyle],
                ['v' => (int)$f['returns_total'], 't' => 'n', 's' => $moneyStyle],
                ['v' => (int)$f['expenses_cash'], 't' => 'n', 's' => $moneyStyle],
                ['v' => (int)$f['expected_cash'], 't' => 'n', 's' => $moneyStyle],
                ['v' => (int)$f['closing_cash'], 't' => 'n', 's' => $moneyStyle],
                ['v' => $diff, 't' => 'n', 's' => $diffStyle],
                ['v' => (int)$f['net_cash'], 't' => 'n', 's' => $moneyStyle],
                ['v' => $row['status_label'], 's' => $statusStyle],
                ['v' => $row['signals'], 's' => $textStyle],
            ];
        }

        $peakRows = [];
        $peakRows[] = [
            ['v' => $labels['pType'], 's' => 2],
            ['v' => $labels['pShiftId'], 's' => 2],
            ['v' => $labels['pWhen'], 's' => 2],
            ['v' => $labels['pName'], 's' => 2],
            ['v' => $labels['pCategory'], 's' => 2],
            ['v' => $labels['pClientId'], 's' => 2],
            ['v' => $labels['pAmount'], 's' => 2],
        ];

        if ($shiftIds) {
            $peakExpenses = ShiftExpense::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('shift_id', $shiftIds)
                ->orderByRaw('ABS(amount) DESC')
                ->limit(30)
                ->get(['shift_id', 'spent_at', 'title', 'category', 'amount']);

            foreach ($peakExpenses as $e) {
                $peakRows[] = [
                    ['v' => 'expense', 's' => 3],
                    ['v' => (int)$e->shift_id, 't' => 'n', 's' => 3],
                    ['v' => (string)($e->spent_at ?? ''), 's' => 3],
                    ['v' => (string)($e->title ?? ''), 's' => 3],
                    ['v' => (string)($e->category ?? ''), 's' => 3],
                    ['v' => '', 's' => 3],
                    ['v' => abs((int)$e->amount), 't' => 'n', 's' => 4],
                ];
            }

            $peakReturns = ReturnRecord::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('shift_id', $shiftIds)
                ->where('payment_method', 'cash')
                ->orderByRaw('ABS(amount) DESC')
                ->limit(30)
                ->get(['shift_id', 'created_at', 'client_id', 'amount']);

            foreach ($peakReturns as $r) {
                $peakRows[] = [
                    ['v' => 'cash_return', 's' => 3],
                    ['v' => (int)$r->shift_id, 't' => 'n', 's' => 3],
                    ['v' => (string)($r->created_at ?? ''), 's' => 3],
                    ['v' => '', 's' => 3],
                    ['v' => '', 's' => 3],
                    ['v' => (int)($r->client_id ?? 0), 't' => 'n', 's' => 3],
                    ['v' => abs((int)$r->amount), 't' => 'n', 's' => 4],
                ];
            }
        }

        $sheet1 = $this->xlsxSheetXml($dashboardRows, [14, 16, 14, 14, 16, 14, 12, 16, 14, 14, 14, 18], 'rId1', [
            'hide_grid' => true,
            'merge_cells' => [
                'A1:L2',
                'B3:D3', 'F3:H3', 'J3:L3',
                'A5:C5', 'D5:F5', 'G5:I5', 'J5:L5',
                'A6:C7', 'D6:F7', 'G6:I7', 'J6:L7',
                'A9:C9', 'D9:F9', 'G9:I9', 'J9:L9',
                'A10:C11', 'D10:F11', 'G10:I11', 'J10:L11',
                'A12:F12', 'G12:L12',
                'A13:F13', 'A14:F14', 'A15:F15', 'A16:F16', 'A17:F17', 'A18:F18',
            ],
            'row_heights' => [1 => 34, 2 => 26, 3 => 22, 5 => 19, 6 => 34, 9 => 19, 10 => 34, 12 => 22, 13 => 24, 14 => 24, 15 => 24, 16 => 24, 17 => 24, 18 => 24],
        ]);
        $sheet2 = $this->xlsxSheetXml($shiftRows, [10, 18, 18, 18, 18, 14, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 14, 60], null, [
            'hide_grid' => true,
            'freeze_rows' => 1,
            'auto_filter' => 'A1:R1',
        ]);
        $sheet3 = $this->xlsxSheetXml($peakRows, [14, 10, 18, 24, 18, 10, 14], null, [
            'hide_grid' => true,
            'freeze_rows' => 1,
            'auto_filter' => 'A1:G1',
        ]);

        $dashboardImage = $this->buildDashboardImage([
            'period' => $periodLabel,
            'generated' => now()->format('Y-m-d H:i:s'),
            'shifts' => (int)count($rows),
            'cash_in' => (int)$sumCashIn,
            'net_cash' => (int)$sumNetCash,
            'expenses' => (int)$sumExpenses,
            'returns' => (int)$sumReturnsTotal,
            'overage' => (int)$sumOverage,
            'shortage' => (int)$sumShortage,
            'carried' => (int)collect($rows)->sum(fn($r) => (int)($r['finance']['carry_to_next_opening'] ?? 0)),
            'expected' => (int)$sumExpected,
            'reconciled_pct' => (float)$reconciledPct,
        ], $labels);

        $liveRows = [];
        $liveRows[] = [
            ['v' => ($labels['title'] ?? 'Dashboard') . ' • LIVE', 's' => 1],
            ['v' => '', 's' => 1], ['v' => '', 's' => 1], ['v' => '', 's' => 1],
            ['v' => '', 's' => 1], ['v' => '', 's' => 1], ['v' => '', 's' => 1], ['v' => '', 's' => 1],
        ];
        $liveRows[] = [
            ['v' => ($labels['period'] ?? 'Period') . ': ' . $periodLabel, 's' => 14],
            ['v' => '', 's' => 14], ['v' => '', 's' => 14], ['v' => '', 's' => 14],
            ['v' => ($labels['generated'] ?? 'Generated') . ': ' . now()->format('Y-m-d H:i:s'), 's' => 14],
            ['v' => '', 's' => 14], ['v' => '', 's' => 14], ['v' => '', 's' => 14],
        ];
        $liveRows[] = [['v' => 'Metric', 's' => 2], ['v' => 'Value (live formula)', 's' => 2], ['v' => '', 's' => 2], ['v' => '', 's' => 2], ['v' => '', 's' => 2], ['v' => '', 's' => 2], ['v' => '', 's' => 2], ['v' => '', 's' => 2]];

        $liveMetrics = [
            [$labels['kpiShifts'] ?? 'Shifts', 'COUNTA(ShiftHistory!$A:$A)-1', 3],
            [$labels['kpiCashIn'] ?? 'Cash in', 'SUM(ShiftHistory!$G:$G)', 4],
            [$labels['kpiCardIn'] ?? 'Card in', 'SUM(ShiftHistory!$H:$H)', 4],
            [$labels['kpiReturns'] ?? 'Returns', 'SUM(ShiftHistory!$K:$K)', 4],
            [$labels['kpiExpenses'] ?? 'Expenses', 'SUM(ShiftHistory!$L:$L)', 4],
            [$labels['kpiExpected'] ?? 'Expected', 'SUM(ShiftHistory!$M:$M)', 4],
            [$labels['kpiClosing'] ?? 'Closing', 'SUM(ShiftHistory!$N:$N)', 4],
            [$labels['kpiNet'] ?? 'Net', 'SUM(ShiftHistory!$P:$P)', 4],
            [$labels['kpiOver'] ?? 'Overage', 'SUMIF(ShiftHistory!$O:$O,\">0\",ShiftHistory!$O:$O)', 4],
            [$labels['kpiShort'] ?? 'Shortage', '-SUMIF(ShiftHistory!$O:$O,\"<0\",ShiftHistory!$O:$O)', 4],
            [$labels['kpiRecon'] ?? 'Reconciled', 'IF((COUNTA(ShiftHistory!$A:$A)-1)=0,0,COUNTIF(ShiftHistory!$O:$O,0)/(COUNTA(ShiftHistory!$A:$A)-1))', 5],
        ];

        foreach ($liveMetrics as [$metricLabel, $formula, $style]) {
            $liveRows[] = [
                ['v' => (string)$metricLabel, 's' => 3],
                ['t' => 'f', 'f' => (string)$formula, 'v' => 0, 's' => (int)$style],
                ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3],
            ];
        }

        // Trend source block for chart (references ShiftHistory rows dynamically by index)
        $liveRows[] = [['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3]];
        $liveRows[] = [['v' => 'Shift ID', 's' => 2], ['v' => 'Net cash', 's' => 2], ['v' => '', 's' => 2], ['v' => '', 's' => 2], ['v' => '', 's' => 2], ['v' => '', 's' => 2], ['v' => '', 's' => 2], ['v' => '', 's' => 2]];
        for ($i = 0; $i < 60; $i++) {
            $liveRows[] = [
                ['t' => 'f', 'f' => 'IFERROR(INDEX(ShiftHistory!$A:$A,ROW()-13),0)', 'v' => 0, 's' => 3],
                ['t' => 'f', 'f' => 'IFERROR(INDEX(ShiftHistory!$P:$P,ROW()-13),0)', 'v' => 0, 's' => 4],
                ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3], ['v' => '', 's' => 3],
            ];
        }

        $sheet4 = $this->xlsxSheetXml($liveRows, [30, 20, 14, 14, 14, 14, 14, 14], 'rId1', [
            'hide_grid' => true,
            'freeze_rows' => 3,
            'merge_cells' => ['A1:H1'],
            'auto_filter' => 'A3:B3',
            'row_heights' => [1 => 34, 2 => 22, 3 => 20],
        ]);

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0"/><numFmt numFmtId="165" formatCode="0.0\\%"/></numFmts>'
            . '<fonts count="9">'
            . '<font><sz val="10"/><color rgb="FFE8F0FF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="24"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFF8FCFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFCA5A5"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF86EFAC"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFDE68A"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF67E8F9"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="20"/><color rgb="FF93C5FD"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFD8B4FE"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="17">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF050A17"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0B162B"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0E1D38"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF33131A"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF133425"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF392D12"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF124058"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF14283F"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF2A1542"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF423211"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF4A231E"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0E1B30"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0A1527"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF12253E"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF162D4A"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FF1F3655"/></left><right style="thin"><color rgb="FF1F3655"/></right><top style="thin"><color rgb="FF1F3655"/></top><bottom style="thin"><color rgb="FF1F3655"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="28">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="3" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="164" fontId="4" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="164" fontId="5" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="8" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="5" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="5" fillId="11" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="6" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="7" fillId="8" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="7" fillId="9" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="7" fillId="10" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="11" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="7" fillId="11" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="16" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="14" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="15" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="14" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="15" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        $drawingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<xdr:twoCellAnchor>'
            . '<xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
            . '<xdr:to><xdr:col>12</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>32</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
            . '<xdr:pic>'
            . '<xdr:nvPicPr><xdr:cNvPr id="2" name="Executive Dashboard"/><xdr:cNvPicPr/></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
            . '</xdr:pic><xdr:clientData/></xdr:twoCellAnchor>'
            . '</xdr:wsDr>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
            . '<Override PartName="/xl/drawings/drawing2.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
            . '<Override PartName="/xl/charts/chart3.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';

        $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="30000" windowHeight="16000"/></bookViews>'
            . '<sheets>'
            . '<sheet name="Dashboard" sheetId="1" r:id="rId1"/>'
            . '<sheet name="ShiftHistory" sheetId="2" r:id="rId2"/>'
            . '<sheet name="TopPeaks" sheetId="3" r:id="rId3"/>'
            . '<sheet name="LiveDashboard" sheetId="4" r:id="rId4"/>'
            . '</sheets><calcPr fullCalcOnLoad="1"/></workbook>';

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
            . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/>'
            . '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $sheet1RelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
            . '</Relationships>';

        $drawingRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/dashboard.png"/>'
            . '</Relationships>';

        $chart3Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<c:lang val="en-US"/>'
            . '<c:chart><c:autoTitleDeleted val="1"/><c:plotArea><c:layout/>'
            . '<c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>'
            . '<c:ser><c:idx val="0"/><c:order val="0"/><c:tx><c:v>Net Trend</c:v></c:tx>'
            . '<c:cat><c:numRef><c:f>LiveDashboard!$A$16:$A$75</c:f></c:numRef></c:cat>'
            . '<c:val><c:numRef><c:f>LiveDashboard!$B$16:$B$75</c:f></c:numRef></c:val>'
            . '<c:marker><c:symbol val="circle"/><c:size val="4"/></c:marker>'
            . '<c:spPr><a:ln w="28575"><a:solidFill><a:srgbClr val="38BDF8"/></a:solidFill></a:ln></c:spPr>'
            . '</c:ser><c:axId val="58200001"/><c:axId val="58200002"/></c:lineChart>'
            . '<c:catAx><c:axId val="58200001"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:axPos val="b"/><c:tickLblPos val="nextTo"/><c:crossAx val="58200002"/><c:crosses val="autoZero"/></c:catAx>'
            . '<c:valAx><c:axId val="58200002"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:axPos val="l"/><c:majorGridlines/><c:numFmt formatCode="#,##0" sourceLinked="0"/><c:tickLblPos val="nextTo"/><c:crossAx val="58200001"/><c:crosses val="autoZero"/></c:valAx>'
            . '</c:plotArea><c:legend><c:legendPos val="t"/><c:layout/></c:legend><c:plotVisOnly val="1"/></c:chart></c:chartSpace>';

        $drawing2Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<xdr:twoCellAnchor><xdr:from><xdr:col>3</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>2</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
            . '<xdr:to><xdr:col>8</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>27</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
            . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="4" name="Live Net Trend"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
            . '<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
            . '<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId1"/>'
            . '</a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor></xdr:wsDr>';

        $drawing2RelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart3.xml"/>'
            . '</Relationships>';

        $sheet4RelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing2.xml"/>'
            . '</Relationships>';

        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->xlsEsc($labels['title']) . '</dc:title>'
            . '<dc:creator>MyCafeCloud</dc:creator>'
            . '<cp:lastModifiedBy>MyCafeCloud</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . now()->toIso8601String() . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . now()->toIso8601String() . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>MyCafeCloud</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>4</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="4" baseType="lpstr"><vt:lpstr>Dashboard</vt:lpstr><vt:lpstr>ShiftHistory</vt:lpstr><vt:lpstr>TopPeaks</vt:lpstr><vt:lpstr>LiveDashboard</vt:lpstr></vt:vector></TitlesOfParts>'
            . '<Company></Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>16.0300</AppVersion>'
            . '</Properties>';

        $tmp = tempnam(sys_get_temp_dir(), 'shift_xlsx_');
        $zip = new \ZipArchive();
        $opened = $zip->open($tmp, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        if ($opened !== true) {
            @unlink($tmp);
            return response()->json(['message' => 'Failed to create XLSX file.'], 500);
        }

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $rootRelsXml);
        $zip->addFromString('docProps/core.xml', $coreXml);
        $zip->addFromString('docProps/app.xml', $appXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);
        $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
        $zip->addFromString('xl/worksheets/sheet3.xml', $sheet3);
        $zip->addFromString('xl/worksheets/sheet4.xml', $sheet4);
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $sheet1RelsXml);
        $zip->addFromString('xl/worksheets/_rels/sheet4.xml.rels', $sheet4RelsXml);
        $zip->addFromString('xl/drawings/drawing1.xml', $drawingXml);
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $drawingRelsXml);
        $zip->addFromString('xl/drawings/drawing2.xml', $drawing2Xml);
        $zip->addFromString('xl/drawings/_rels/drawing2.xml.rels', $drawing2RelsXml);
        $zip->addFromString('xl/charts/chart3.xml', $chart3Xml);
        $zip->addFromString('xl/media/dashboard.png', $dashboardImage);
        $zip->close();

        $content = file_get_contents($tmp);
        @unlink($tmp);

        $filename = 'shift-history-dashboard-' . now()->format('Ymd-His') . '.xlsx';

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    // GET /api/shifts/history/{id}
    public function show(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        /** @var Shift $shift */
        $shift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->addSelect([
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
            ])
            ->with([
                'openedBy:id,login,name,role',
                'closedBy:id,login,name,role',
            ])
            ->withSum('expenses as expenses_cash_total', 'amount')
            ->firstOrFail();

        $financeMap = $this->loadFinanceMap((int)$tenantId, [(int)$shift->id]);
        $bucket = $financeMap[(int)$shift->id] ?? ['tx' => null, 'returns' => null, 'expenses' => null];
        $finance = $this->buildShiftFinance($shift, $bucket['tx'], $bucket['returns'], $bucket['expenses']);
        $shift->setAttribute('reconcile_status', $this->resolveReconcileStatus($finance));

        $transactions = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->whereIn('type', ['topup', 'package', 'subscription'])
            ->with([
                'client:id,login,phone',
            ])
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

        $txByType = ClientTransaction::query()
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
                'created_at'
            ]);

        $byCat = ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->selectRaw("COALESCE(category,'No category') as category, SUM(amount) as total")
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'shift' => $shift,
                'finance' => $finance,
                'transactions' => $transactions,
                'transactions_by_type' => $txByType,
                'returns' => $returns,
                'returns_by_method' => $returnsByMethod,
                'expenses' => $expenses,
                'expenses_by_category' => $byCat,
            ],
        ]);
    }
}

