<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\LicenseKey;
use App\Models\Pc;
use App\Models\ReturnRecord;
use App\Models\Session;
use App\Models\Shift;
use App\Models\ShiftExpense;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantReportService
{
    public function __construct(
        private readonly PaymentAggregationService $payments,
        private readonly TenantReportSummaryService $summary,
    ) {
    }

    public function buildCashBreakdown(int $tenantId, Carbon $from, Carbon $to): array
    {
        return $this->summary->buildCashBreakdown($tenantId, $from, $to);
    }

    public function buildSessionsSummary(int $tenantId, Carbon $from, Carbon $to): array
    {
        return $this->summary->buildSessionsSummary($tenantId, $from, $to);
    }

    public function buildTopClients(int $tenantId, Carbon $from, Carbon $to, int $limit = 10): array
    {
        return $this->summary->buildTopClients($tenantId, $from, $to, $limit);
    }

    public function buildLostRevenue(int $tenantId, Carbon $from, Carbon $to): array
    {
        $report = $this->build($tenantId, $from, $to);
        $summary = (array) ($report['summary'] ?? []);
        $sessions = (array) ($report['sessions'] ?? []);
        $activity = (array) ($report['activity'] ?? []);

        $netSales = (float) ($summary['net_sales'] ?? 0);
        $totalMinutes = (float) ($sessions['total_minutes'] ?? 0);
        $pcsTotal = max(1, (int) ($activity['pcs_total'] ?? 0));
        $periodMinutes = max(1, (int) $from->diffInMinutes($to));
        $capacityMinutes = $pcsTotal * $periodMinutes;
        $revenuePerMinute = $totalMinutes > 0 ? $netSales / $totalMinutes : 0.0;
        $potentialRevenue = $capacityMinutes > 0 ? $revenuePerMinute * $capacityMinutes : 0.0;
        $lostRevenue = max(0.0, $potentialRevenue - $netSales);
        $lostPct = $potentialRevenue > 0 ? round(($lostRevenue / $potentialRevenue) * 100, 1) : 0.0;
        $utilization = $capacityMinutes > 0 ? round(min(100, ($totalMinutes / $capacityMinutes) * 100), 1) : 0.0;

        return [
            'range' => $this->formatRange($from, $to),
            'metrics' => [
                'pcs_total' => $pcsTotal,
                'capacity_minutes' => (int) round($capacityMinutes),
                'used_minutes' => (int) round($totalMinutes),
                'utilization_pct' => $utilization,
                'net_sales' => (int) $netSales,
                'revenue_per_minute' => round($revenuePerMinute, 2),
                'potential_revenue' => (int) round($potentialRevenue),
                'lost_revenue' => (int) round($lostRevenue),
                'lost_revenue_pct' => $lostPct,
            ],
        ];
    }

    public function buildZoneProfitability(int $tenantId, Carbon $from, Carbon $to): array
    {
        $report = $this->build($tenantId, $from, $to);
        $zonesItems = (array) ($report['zones']['items'] ?? []);
        $zoneExpr = "COALESCE(NULLIF(TRIM(COALESCE(zone,'')), ''), 'No zone')";

        $zonePcRows = Pc::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            })
            ->selectRaw("{$zoneExpr} AS zone_name, COUNT(*) AS pcs_count")
            ->groupByRaw($zoneExpr)
            ->get();

        $zonePcMap = [];
        foreach ($zonePcRows as $row) {
            $zonePcMap[(string) $row->zone_name] = (int) $row->pcs_count;
        }

        $zoneMap = [];
        foreach ($zonesItems as $row) {
            $zoneName = (string) ($row['zone'] ?? 'No zone');
            $zoneMap[$zoneName] = is_array($row) ? $row : [];
        }

        $items = [];
        $allZoneNames = array_unique(array_merge(array_keys($zoneMap), array_keys($zonePcMap)));
        foreach ($allZoneNames as $zoneName) {
            $row = $zoneMap[$zoneName] ?? [];
            $revenue = (int) ($row['revenue'] ?? 0);
            $sessionsCount = (int) ($row['sessions_count'] ?? 0);
            $minutes = (int) ($row['minutes'] ?? 0);
            $pcsCount = (int) ($zonePcMap[$zoneName] ?? 0);

            $items[] = [
                'zone' => $zoneName,
                'pcs_count' => $pcsCount,
                'revenue' => $revenue,
                'sessions_count' => $sessionsCount,
                'minutes' => $minutes,
                'revenue_per_pc' => $pcsCount > 0 ? round($revenue / $pcsCount, 1) : 0.0,
                'revenue_per_session' => $sessionsCount > 0 ? round($revenue / $sessionsCount, 1) : 0.0,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return $right['revenue_per_pc'] <=> $left['revenue_per_pc'];
        });

        return [
            'range' => $this->formatRange($from, $to),
            'zones' => $items,
        ];
    }

    public function buildAbComparison(
        int $tenantId,
        Carbon $fromA,
        Carbon $toA,
        Carbon $fromB,
        Carbon $toB,
    ): array {
        $reportA = $this->build($tenantId, $fromA, $toA);
        $reportB = $this->build($tenantId, $fromB, $toB);
        $salesA = (array) ($reportA['sales'] ?? []);
        $salesB = (array) ($reportB['sales'] ?? []);
        $summaryA = (array) ($reportA['summary'] ?? []);
        $summaryB = (array) ($reportB['summary'] ?? []);

        $metrics = [
            'net_sales' => [(int) ($summaryA['net_sales'] ?? 0), (int) ($summaryB['net_sales'] ?? 0)],
            'gross_sales' => [(int) ($summaryA['gross_sales'] ?? 0), (int) ($summaryB['gross_sales'] ?? 0)],
            'sessions_count' => [(int) ($summaryA['sessions_count'] ?? 0), (int) ($summaryB['sessions_count'] ?? 0)],
            'avg_session_minutes' => [(float) ($summaryA['avg_session_minutes'] ?? 0), (float) ($summaryB['avg_session_minutes'] ?? 0)],
            'topup_total' => [(int) ($salesA['topup_total'] ?? 0), (int) ($salesB['topup_total'] ?? 0)],
            'package_total' => [(int) ($salesA['package_total'] ?? 0), (int) ($salesB['package_total'] ?? 0)],
            'subscription_total' => [(int) ($salesA['subscription_total'] ?? 0), (int) ($salesB['subscription_total'] ?? 0)],
        ];

        $diff = [];
        foreach ($metrics as $key => [$a, $b]) {
            $delta = $b - $a;
            $diff[$key] = [
                'a' => $a,
                'b' => $b,
                'delta' => $delta,
                'delta_pct' => $a > 0 ? round(($delta / $a) * 100, 1) : null,
            ];
        }

        return [
            'range_a' => [
                'from' => $fromA->toDateString(),
                'to' => $toA->toDateString(),
            ],
            'range_b' => [
                'from' => $fromB->toDateString(),
                'to' => $toB->toDateString(),
            ],
            'metrics' => $diff,
        ];
    }

    public function buildBranchComparison(
        int $tenantId,
        string $licenseKey,
        Carbon $from,
        Carbon $to,
    ): array {
        $license = LicenseKey::query()
            ->with('tenant:id,name,status')
            ->where('key', $licenseKey)
            ->first();

        if (!$license || !$license->tenant_id) {
            throw ValidationException::withMessages([
                'license_key' => 'License key not found',
            ]);
        }

        if ((int) $license->tenant_id === $tenantId) {
            throw ValidationException::withMessages([
                'license_key' => 'Enter another branch license key',
            ]);
        }

        $leftTenant = Tenant::query()->find($tenantId, ['id', 'name', 'status']);
        $rightTenant = $license->tenant
            ? $license->tenant->only(['id', 'name', 'status'])
            : ['id' => (int) $license->tenant_id, 'name' => '-', 'status' => 'unknown'];

        $leftReport = $this->build($tenantId, $from, $to);
        $rightReport = $this->build((int) $license->tenant_id, $from, $to);

        return [
            'range' => $this->formatRange($from, $to),
            'left' => [
                'tenant' => $leftTenant,
                'report' => $leftReport,
            ],
            'right' => [
                'tenant' => $rightTenant,
                'license' => [
                    'key' => $license->key,
                    'status' => $license->status,
                    'expires_at' => optional($license->expires_at)->toDateTimeString(),
                ],
                'report' => $rightReport,
            ],
            'comparison' => $this->compareKpis($leftReport, $rightReport),
        ];
    }

    public function build(int $tenantId, Carbon $from, Carbon $to): array
    {
        $txBase = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to]);

        $sessionBase = Session::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$from, $to]);

        $returnBase = ReturnRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to]);

        $expenseBase = ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('spent_at', [$from, $to])
                    ->orWhere(function ($qq) use ($from, $to) {
                        $qq->whereNull('spent_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            });
        $zoneExpr = "COALESCE(NULLIF(TRIM(COALESCE(pcs.zone,'')), ''), 'No zone')";

        $baseTxMetrics = (clone $txBase)->selectRaw("
            COALESCE(SUM(CASE WHEN type='topup' THEN amount ELSE 0 END),0) AS topup_total,
            COALESCE(SUM(CASE WHEN type='package' THEN amount ELSE 0 END),0) AS package_total,
            COALESCE(SUM(CASE WHEN type='subscription' THEN amount ELSE 0 END),0) AS subscription_total,
            COALESCE(SUM(CASE WHEN type='transfer_in' THEN amount ELSE 0 END),0) AS transfer_in_total,
            COALESCE(SUM(CASE WHEN type='transfer_out' THEN ABS(amount) ELSE 0 END),0) AS transfer_out_total,
            COALESCE(SUM(CASE WHEN type='tier_upgrade_bonus' THEN bonus_amount ELSE 0 END),0) AS tier_bonus_total,
            COALESCE(SUM(CASE WHEN type='topup' THEN bonus_amount ELSE 0 END),0) AS topup_bonus_total,

            COUNT(*) AS tx_count,
            SUM(CASE WHEN type='topup' THEN 1 ELSE 0 END) AS topup_count,
            SUM(CASE WHEN type='package' THEN 1 ELSE 0 END) AS package_count,
            SUM(CASE WHEN type='subscription' THEN 1 ELSE 0 END) AS subscription_count
        ")->first();

        $salesMetrics = $this->payments->salesSummary(clone $txBase, ['topup', 'package', 'subscription']);
        $txMetrics = (object) array_merge(
            $this->rowToArray($baseTxMetrics),
            $this->rowToArray($salesMetrics),
        );

        $sessionMetrics = (clone $sessionBase)->selectRaw("
            COUNT(*) AS sessions_count,
            COALESCE(SUM(price_total),0) AS sessions_revenue,
            COALESCE(AVG(EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at))/60),0) AS avg_session_minutes,
            COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at))/60),0) AS total_session_minutes
        ")->first();

        $baseReturnMetrics = (clone $returnBase)->selectRaw("
            COUNT(*) AS returns_count,
            COALESCE(SUM(amount),0) AS returns_total
        ")->first();

        $returnTotals = $this->payments->returnSummary(clone $returnBase);
        $returnMetrics = (object) array_merge(
            $this->rowToArray($baseReturnMetrics),
            $this->rowToArray($returnTotals),
        );

        $expensesTotal = (int)(clone $expenseBase)->sum('amount');

        $pcBase = Pc::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            });

        $pcsTotal = (int)(clone $pcBase)->count();
        $pcsOnline = (int)(clone $pcBase)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subMinutes((int) config('domain.pc.online_window_minutes', 3)))
            ->count();

        $activeSessionsNow = (int)Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $clientsTotal = (int)Client::query()
            ->where('tenant_id', $tenantId)
            ->count();

        $clientsActive = (int)Client::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $clientsInPeriod = (int)(clone $sessionBase)
            ->whereNotNull('client_id')
            ->distinct('client_id')
            ->count('client_id');

        $topClientsRows = Session::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('client_id')
            ->selectRaw("
                client_id,
                COUNT(*) AS sessions_count,
                COALESCE(SUM(price_total),0) AS spent,
                COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at))/60),0) AS minutes
            ")
            ->groupBy('client_id')
            ->orderByDesc('spent')
            ->limit(10)
            ->get();

        $topClientIds = $topClientsRows->pluck('client_id')->all();
        $topClientsMap = Client::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $topClientIds)
            ->get(['id', 'login', 'account_id', 'phone'])
            ->keyBy('id');

        $topClients = $topClientsRows->map(function ($row) use ($topClientsMap) {
            $client = $topClientsMap->get((int)$row->client_id);

            return [
                'client_id' => (int)$row->client_id,
                'login' => $client->login ?? null,
                'account_id' => $client->account_id ?? null,
                'phone' => $client->phone ?? null,
                'sessions_count' => (int)($row->sessions_count ?? 0),
                'spent' => (int)($row->spent ?? 0),
                'minutes' => (int)round((float)($row->minutes ?? 0)),
            ];
        })->values();

        $dailySalesRows = (clone $txBase)->selectRaw("
            DATE(created_at) AS day,
            COALESCE(SUM(CASE WHEN type='topup' THEN amount ELSE 0 END),0) AS topup_total,
            COALESCE(SUM(CASE WHEN type='package' THEN amount ELSE 0 END),0) AS package_total,
            COALESCE(SUM(CASE WHEN type='subscription' THEN amount ELSE 0 END),0) AS subscription_total
        ")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn($row) => (string)$row->day);
        $dailyBonusRows = (clone $txBase)->selectRaw("
            DATE(created_at) AS day,
            COALESCE(SUM(CASE WHEN type='topup' THEN bonus_amount ELSE 0 END),0) AS topup_bonus_total,
            COALESCE(SUM(CASE WHEN type='tier_upgrade_bonus' THEN bonus_amount ELSE 0 END),0) AS tier_bonus_total
        ")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn($row) => (string)$row->day);

        $dailySessionRows = (clone $sessionBase)->selectRaw("
            DATE(started_at) AS day,
            COUNT(*) AS sessions_count,
            COALESCE(SUM(price_total),0) AS sessions_revenue
        ")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn($row) => (string)$row->day);

        $dailyReturnRows = (clone $returnBase)->selectRaw("
            DATE(created_at) AS day,
            COALESCE(SUM(amount),0) AS returns_total
        ")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn($row) => (string)$row->day);
        $hourlyRows = (clone $sessionBase)
            ->selectRaw("
                EXTRACT(HOUR FROM started_at)::int AS hour_no,
                COUNT(*) AS sessions_count,
                COALESCE(SUM(price_total),0) AS revenue
            ")
            ->groupByRaw("EXTRACT(HOUR FROM started_at)::int")
            ->orderBy('hour_no')
            ->get()
            ->keyBy('hour_no');
        $weekdayRows = (clone $sessionBase)
            ->selectRaw("
                EXTRACT(DOW FROM started_at)::int AS day_no,
                COUNT(*) AS sessions_count,
                COALESCE(SUM(price_total),0) AS revenue
            ")
            ->groupByRaw("EXTRACT(DOW FROM started_at)::int")
            ->orderBy('day_no')
            ->get()
            ->keyBy('day_no');
        $sessionStatusRows = (clone $sessionBase)
            ->selectRaw("status, COUNT(*) AS sessions_count")
            ->groupBy('status')
            ->orderByDesc('sessions_count')
            ->get();
        $txTypeRows = (clone $txBase)
            ->selectRaw("type, COUNT(*) AS tx_count, COALESCE(SUM(amount),0) AS amount_total")
            ->groupBy('type')
            ->orderByDesc('tx_count')
            ->get();
        $returnTypeRows = (clone $returnBase)
            ->selectRaw("type, COUNT(*) AS returns_count, COALESCE(SUM(amount),0) AS amount_total")
            ->groupBy('type')
            ->orderByDesc('returns_count')
            ->get();
        $zonesRows = Session::query()
            ->leftJoin('pcs', 'pcs.id', '=', 'sessions.pc_id')
            ->where('sessions.tenant_id', $tenantId)
            ->whereBetween('sessions.started_at', [$from, $to])
            ->selectRaw("
                {$zoneExpr} AS zone_name,
                COUNT(sessions.id) AS sessions_count,
                COALESCE(SUM(sessions.price_total),0) AS revenue,
                COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(sessions.ended_at, NOW()) - sessions.started_at))/60),0) AS minutes
            ")
            ->groupByRaw($zoneExpr)
            ->orderByDesc('revenue')
            ->get();
        $pcUsageRows = Pc::query()
            ->leftJoin('sessions', function ($join) use ($from, $to) {
                $join->on('sessions.pc_id', '=', 'pcs.id')
                    ->whereBetween('sessions.started_at', [$from, $to]);
            })
            ->where('pcs.tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNull('pcs.is_hidden')->orWhere('pcs.is_hidden', false);
            })
            ->selectRaw("
                pcs.id AS pc_id,
                pcs.code AS pc_code,
                {$zoneExpr} AS zone_name,
                COUNT(sessions.id) AS sessions_count,
                COALESCE(SUM(sessions.price_total),0) AS revenue,
                COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(sessions.ended_at, NOW()) - sessions.started_at))/60),0) AS minutes
            ")
            ->groupBy('pcs.id', 'pcs.code')
            ->groupByRaw($zoneExpr)
            ->get();
        $pcStatusRows = (clone $pcBase)
            ->selectRaw("status, COUNT(*) AS pcs_count")
            ->groupBy('status')
            ->orderByDesc('pcs_count')
            ->get();
        $operatorTxRows = ClientTransaction::query()
            ->leftJoin('operators', 'operators.id', '=', 'client_transactions.operator_id')
            ->where('client_transactions.tenant_id', $tenantId)
            ->whereBetween('client_transactions.created_at', [$from, $to])
            ->whereNotNull('client_transactions.operator_id')
            ->selectRaw("
                client_transactions.operator_id,
                operators.login AS operator_login,
                operators.name AS operator_name,
                COUNT(*) AS tx_count,
                COALESCE(SUM(CASE WHEN client_transactions.type IN ('topup','package','subscription') THEN client_transactions.amount ELSE 0 END),0) AS sales_amount,
                COALESCE(SUM(CASE WHEN client_transactions.type='topup' THEN 1 ELSE 0 END),0) AS topup_count,
                COALESCE(SUM(CASE WHEN client_transactions.type='package' THEN 1 ELSE 0 END),0) AS package_count,
                COALESCE(SUM(CASE WHEN client_transactions.type='subscription' THEN 1 ELSE 0 END),0) AS subscription_count
            ")
            ->groupBy('client_transactions.operator_id', 'operators.login', 'operators.name')
            ->get();
        $operatorSessionRows = Session::query()
            ->leftJoin('operators', 'operators.id', '=', 'sessions.operator_id')
            ->where('sessions.tenant_id', $tenantId)
            ->whereBetween('sessions.started_at', [$from, $to])
            ->whereNotNull('sessions.operator_id')
            ->selectRaw("
                sessions.operator_id,
                operators.login AS operator_login,
                operators.name AS operator_name,
                COUNT(*) AS sessions_count,
                COALESCE(SUM(sessions.price_total),0) AS sessions_revenue
            ")
            ->groupBy('sessions.operator_id', 'operators.login', 'operators.name')
            ->get();
        $shiftMetrics = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw("
                COUNT(*) AS shifts_count,
                COALESCE(SUM(CASE WHEN closed_at IS NULL THEN 1 ELSE 0 END),0) AS open_shifts,
                COALESCE(SUM(opening_cash),0) AS opening_cash_total,
                COALESCE(SUM(closing_cash),0) AS closing_cash_total,
                COALESCE(SUM(diff_overage),0) AS diff_overage_total,
                COALESCE(SUM(diff_shortage),0) AS diff_shortage_total
            ")
            ->first();
        $newClientsInPeriod = (int)Client::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->count();
        $newClientsWithSessions = (int)Session::query()
            ->join('clients', 'clients.id', '=', 'sessions.client_id')
            ->where('sessions.tenant_id', $tenantId)
            ->whereBetween('sessions.started_at', [$from, $to])
            ->whereBetween('clients.created_at', [$from, $to])
            ->distinct('sessions.client_id')
            ->count('sessions.client_id');

        $series = [];
        $cursor = $from->copy()->startOfDay();
        $endDate = $to->copy()->startOfDay();
        $longestActiveStreak = 0;
        $currentActiveStreak = 0;
        while ($cursor->lte($endDate)) {
            $day = $cursor->toDateString();
            $txDay = $dailySalesRows->get($day);
            $bonusDay = $dailyBonusRows->get($day);
            $sessionDay = $dailySessionRows->get($day);
            $returnDay = $dailyReturnRows->get($day);

            $topupDay = (int)($txDay->topup_total ?? 0);
            $packageDay = (int)($txDay->package_total ?? 0);
            $subscriptionDay = (int)($txDay->subscription_total ?? 0);
            $grossDay = $topupDay + $packageDay + $subscriptionDay;
            $returnsDay = (int)($returnDay->returns_total ?? 0);
            $topupBonusDay = (int)($bonusDay->topup_bonus_total ?? 0);
            $tierBonusDay = (int)($bonusDay->tier_bonus_total ?? 0);

            $series[] = [
                'date' => $day,
                'sessions_count' => (int)($sessionDay->sessions_count ?? 0),
                'sessions_revenue' => (int)($sessionDay->sessions_revenue ?? 0),
                'topup_total' => $topupDay,
                'package_total' => $packageDay,
                'subscription_total' => $subscriptionDay,
                'gross_sales' => $grossDay,
                'returns_total' => $returnsDay,
                'net_sales' => $grossDay - $returnsDay,
                'topup_bonus_total' => $topupBonusDay,
                'tier_bonus_total' => $tierBonusDay,
                'total_bonus' => $topupBonusDay + $tierBonusDay,
            ];
            if ((int)($sessionDay->sessions_count ?? 0) > 0) {
                $currentActiveStreak++;
                $longestActiveStreak = max($longestActiveStreak, $currentActiveStreak);
            } else {
                $currentActiveStreak = 0;
            }

            $cursor->addDay();
        }

        $topupTotal = (int)($txMetrics->topup_total ?? 0);
        $packageTotal = (int)($txMetrics->package_total ?? 0);
        $subscriptionTotal = (int)($txMetrics->subscription_total ?? 0);
        $grossSales = $topupTotal + $packageTotal + $subscriptionTotal;

        $returnsTotal = (int)($returnMetrics->returns_total ?? 0);
        $returnsCashTotal = (int)($returnMetrics->returns_cash_total ?? 0);
        $returnsCardTotal = (int)($returnMetrics->returns_card_total ?? 0);
        $returnsBalanceTotal = (int)($returnMetrics->returns_balance_total ?? 0);

        $cashSalesTotal = (int)($txMetrics->cash_sales_total ?? 0);
        $cardSalesTotal = (int)($txMetrics->card_sales_total ?? 0);
        $balanceSalesTotal = (int)($txMetrics->balance_sales_total ?? 0);

        $totalSessionMinutes = (float)($sessionMetrics->total_session_minutes ?? 0);
        $periodMinutes = max(1, (int)$from->diffInMinutes($to));
        $capacityMinutes = $pcsTotal > 0 ? $pcsTotal * $periodMinutes : 0;
        $utilizationPct = $capacityMinutes > 0
            ? round(min(100, ($totalSessionMinutes / $capacityMinutes) * 100), 1)
            : 0.0;
        $sessionsCount = (int)($sessionMetrics->sessions_count ?? 0);
        $netSales = $grossSales - $returnsTotal;
        $hourlyDistribution = [];
        for ($h = 0; $h < 24; $h++) {
            $row = $hourlyRows->get($h);
            $hourlyDistribution[] = [
                'hour' => $h,
                'label' => str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00',
                'sessions_count' => (int)($row->sessions_count ?? 0),
                'revenue' => (int)($row->revenue ?? 0),
            ];
        }
        $weekdayLabels = ['Yak', 'Dush', 'Sesh', 'Chor', 'Pay', 'Juma', 'Shan'];
        $weekdayDistribution = [];
        foreach ($weekdayLabels as $idx => $label) {
            $row = $weekdayRows->get($idx);
            $weekdayDistribution[] = [
                'weekday_no' => $idx,
                'label' => $label,
                'sessions_count' => (int)($row->sessions_count ?? 0),
                'revenue' => (int)($row->revenue ?? 0),
            ];
        }
        $peakHour = collect($hourlyDistribution)->sortByDesc('sessions_count')->first();
        if (($peakHour['sessions_count'] ?? 0) <= 0) {
            $peakHour = null;
        }
        $peakWeekday = collect($weekdayDistribution)->sortByDesc('sessions_count')->first();
        if (($peakWeekday['sessions_count'] ?? 0) <= 0) {
            $peakWeekday = null;
        }
        $peakSalesDay = collect($series)->sortByDesc('net_sales')->first();
        if (($peakSalesDay['net_sales'] ?? 0) <= 0) {
            $peakSalesDay = null;
        }
        $peakSessionDay = collect($series)->sortByDesc('sessions_count')->first();
        if (($peakSessionDay['sessions_count'] ?? 0) <= 0) {
            $peakSessionDay = null;
        }
        $peakBonusDay = collect($series)->sortByDesc('total_bonus')->first();
        if (($peakBonusDay['total_bonus'] ?? 0) <= 0) {
            $peakBonusDay = null;
        }
        $zones = $zonesRows->map(function ($row) {
            return [
                'zone' => $row->zone_name,
                'sessions_count' => (int)($row->sessions_count ?? 0),
                'revenue' => (int)($row->revenue ?? 0),
                'minutes' => (int)round((float)($row->minutes ?? 0)),
            ];
        })->values();
        $topZone = $zones->sortByDesc('revenue')->first();
        $pcPerformance = $pcUsageRows->map(function ($row) {
            return [
                'pc_id' => (int)$row->pc_id,
                'pc_code' => $row->pc_code ?: ('PC #' . $row->pc_id),
                'zone' => $row->zone_name,
                'sessions_count' => (int)($row->sessions_count ?? 0),
                'revenue' => (int)($row->revenue ?? 0),
                'minutes' => (int)round((float)($row->minutes ?? 0)),
            ];
        });
        $topPcs = $pcPerformance->sortByDesc('revenue')->take(10)->values();
        $underusedPcs = $pcPerformance->sortBy('sessions_count')->take(10)->values();
        $operatorMap = [];
        foreach ($operatorTxRows as $row) {
            $opId = (int)$row->operator_id;
            $operatorMap[$opId] = [
                'operator_id' => $opId,
                'operator' => $row->operator_login ?: ($row->operator_name ?: ('operator #' . $opId)),
                'tx_count' => (int)($row->tx_count ?? 0),
                'sales_amount' => (int)($row->sales_amount ?? 0),
                'topup_count' => (int)($row->topup_count ?? 0),
                'package_count' => (int)($row->package_count ?? 0),
                'subscription_count' => (int)($row->subscription_count ?? 0),
                'sessions_count' => 0,
                'sessions_revenue' => 0,
            ];
        }
        foreach ($operatorSessionRows as $row) {
            $opId = (int)$row->operator_id;
            if (!isset($operatorMap[$opId])) {
                $operatorMap[$opId] = [
                    'operator_id' => $opId,
                    'operator' => $row->operator_login ?: ($row->operator_name ?: ('operator #' . $opId)),
                    'tx_count' => 0,
                    'sales_amount' => 0,
                    'topup_count' => 0,
                    'package_count' => 0,
                    'subscription_count' => 0,
                    'sessions_count' => 0,
                    'sessions_revenue' => 0,
                ];
            }
            $operatorMap[$opId]['sessions_count'] = (int)($row->sessions_count ?? 0);
            $operatorMap[$opId]['sessions_revenue'] = (int)($row->sessions_revenue ?? 0);
        }
        $operatorPerformance = collect(array_values($operatorMap))
            ->sortByDesc(function ($row) {
                $row = is_array($row) ? $row : [];
                return ((int)$row['sales_amount']) + ((int)$row['sessions_revenue']);
            })
            ->take(12)
            ->values();
        $avgTopupCheck = (int)($txMetrics->topup_count ?? 0) > 0
            ? round($topupTotal / (int)$txMetrics->topup_count, 1)
            : 0.0;
        $shiftsCount = (int)($shiftMetrics->shifts_count ?? 0);
        $avgRevenuePerShift = $shiftsCount > 0
            ? round($netSales / $shiftsCount, 1)
            : 0.0;
        $avgSessionRevenue = $sessionsCount > 0
            ? round((int)($sessionMetrics->sessions_revenue ?? 0) / $sessionsCount, 1)
            : 0.0;
        $avgSessionsPerClient = $clientsInPeriod > 0
            ? round($sessionsCount / $clientsInPeriod, 2)
            : 0.0;
        $arpu = $clientsInPeriod > 0
            ? round($netSales / $clientsInPeriod, 1)
            : 0.0;
        $returningClients = max(0, $clientsInPeriod - $newClientsWithSessions);
        $periodDays = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subDays($periodDays - 1)->startOfDay();
        $previousSnapshot = $this->buildKpiSnapshot($tenantId, $prevFrom, $prevTo);
        $currentSnapshot = [
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'sessions_count' => $sessionsCount,
            'avg_session_minutes' => round((float)($sessionMetrics->avg_session_minutes ?? 0), 1),
            'utilization_pct' => $utilizationPct,
            'cash_net' => $cashSalesTotal - $returnsCashTotal - $expensesTotal,
            'card_net' => $cardSalesTotal - $returnsCardTotal,
            'active_sessions_now' => $activeSessionsNow,
            'clients_in_period' => $clientsInPeriod,
        ];
        $growth = [];
        foreach ($currentSnapshot as $key => $value) {
            $growth[$key] = $this->calcGrowthValue((float)$value, (float)($previousSnapshot[$key] ?? 0));
        }
        $dailyCollection = collect($series);
        $dailyAverageNet = round((float)$dailyCollection->avg('net_sales'), 1);
        $dailyMinNet = (int)$dailyCollection->min('net_sales');
        $dailyMaxNet = (int)$dailyCollection->max('net_sales');
        $projection = [
            'daily_average_net' => $dailyAverageNet,
            'daily_min_net' => $dailyMinNet,
            'daily_max_net' => $dailyMaxNet,
            'yearly_from_average' => (int)round($dailyAverageNet * 365),
            'yearly_from_min' => (int)round($dailyMinNet * 365),
            'yearly_from_max' => (int)round($dailyMaxNet * 365),
        ];

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $periodDays,
                'previous_from' => $prevFrom->toDateString(),
                'previous_to' => $prevTo->toDateString(),
            ],
            'summary' => [
                'gross_sales' => $grossSales,
                'net_sales' => $netSales,
                'sessions_revenue' => (int)($sessionMetrics->sessions_revenue ?? 0),
                'sessions_count' => $sessionsCount,
                'avg_session_minutes' => round((float)($sessionMetrics->avg_session_minutes ?? 0), 1),
                'utilization_pct' => $utilizationPct,
                'expenses_total' => $expensesTotal,
                'returns_total' => $returnsTotal,
                'returns_count' => (int)($returnMetrics->returns_count ?? 0),
                'tx_count' => (int)($txMetrics->tx_count ?? 0),
                'avg_revenue_per_shift' => $avgRevenuePerShift,
            ],
            'sales' => [
                'topup_total' => $topupTotal,
                'package_total' => $packageTotal,
                'subscription_total' => $subscriptionTotal,
                'topup_bonus_total' => (int)($txMetrics->topup_bonus_total ?? 0),
                'tier_bonus_total' => (int)($txMetrics->tier_bonus_total ?? 0),
                'topup_count' => (int)($txMetrics->topup_count ?? 0),
                'package_count' => (int)($txMetrics->package_count ?? 0),
                'subscription_count' => (int)($txMetrics->subscription_count ?? 0),
            ],
            'payments' => [
                'cash_sales_total' => $cashSalesTotal,
                'card_sales_total' => $cardSalesTotal,
                'balance_sales_total' => $balanceSalesTotal,
                'cash_net' => $cashSalesTotal - $returnsCashTotal - $expensesTotal,
                'card_net' => $cardSalesTotal - $returnsCardTotal,
                'balance_net' => $balanceSalesTotal - $returnsBalanceTotal,
                'returns_cash_total' => $returnsCashTotal,
                'returns_card_total' => $returnsCardTotal,
                'returns_balance_total' => $returnsBalanceTotal,
            ],
            'transfers' => [
                'transfer_in_total' => (int)($txMetrics->transfer_in_total ?? 0),
                'transfer_out_total' => (int)($txMetrics->transfer_out_total ?? 0),
                'net_transfer' => (int)($txMetrics->transfer_in_total ?? 0) - (int)($txMetrics->transfer_out_total ?? 0),
            ],
            'activity' => [
                'pcs_total' => $pcsTotal,
                'pcs_online' => $pcsOnline,
                'active_sessions_now' => $activeSessionsNow,
                'clients_total' => $clientsTotal,
                'clients_active' => $clientsActive,
                'clients_in_period' => $clientsInPeriod,
            ],
            'sessions' => [
                'total_minutes' => (int)round($totalSessionMinutes),
                'total_hours' => round($totalSessionMinutes / 60, 2),
                'avg_session_revenue' => $avgSessionRevenue,
                'avg_sessions_per_client' => $avgSessionsPerClient,
                'longest_active_streak_days' => (int)$longestActiveStreak,
                'peak_hour' => $peakHour,
                'peak_weekday' => $peakWeekday,
                'peak_session_day' => $peakSessionDay,
                'peak_sales_day' => $peakSalesDay,
                'peak_bonus_day' => $peakBonusDay,
                'hourly_distribution' => $hourlyDistribution,
                'weekday_distribution' => $weekdayDistribution,
                'status_distribution' => $sessionStatusRows->map(function ($row) {
                    return [
                        'status' => (string)$row->status,
                        'sessions_count' => (int)($row->sessions_count ?? 0),
                    ];
                })->values(),
            ],
            'zones' => [
                'top_zone' => $topZone,
                'items' => $zones,
            ],
            'pcs' => [
                'status_distribution' => $pcStatusRows->map(function ($row) {
                    return [
                        'status' => (string)$row->status,
                        'pcs_count' => (int)($row->pcs_count ?? 0),
                    ];
                })->values(),
                'top' => $topPcs,
                'underused' => $underusedPcs,
            ],
            'operators' => [
                'active_operators_in_period' => (int)$operatorPerformance->count(),
                'items' => $operatorPerformance,
            ],
            'clients' => [
                'new_clients_in_period' => $newClientsInPeriod,
                'new_clients_with_sessions' => $newClientsWithSessions,
                'returning_clients' => $returningClients,
                'arpu' => $arpu,
                'avg_topup_check' => $avgTopupCheck,
            ],
            'insights' => [
                'avg_revenue_per_shift' => $avgRevenuePerShift,
                'avg_topup_check' => $avgTopupCheck,
                'projection' => $projection,
            ],
            'returns' => [
                'items' => $returnTypeRows->map(function ($row) {
                    return [
                        'type' => (string)$row->type,
                        'returns_count' => (int)($row->returns_count ?? 0),
                        'amount_total' => (int)($row->amount_total ?? 0),
                    ];
                })->values(),
            ],
            'transactions' => [
                'items' => $txTypeRows->map(function ($row) {
                    return [
                        'type' => (string)$row->type,
                        'tx_count' => (int)($row->tx_count ?? 0),
                        'amount_total' => (int)($row->amount_total ?? 0),
                    ];
                })->values(),
            ],
            'shifts' => [
                'shifts_count' => (int)($shiftMetrics->shifts_count ?? 0),
                'open_shifts' => (int)($shiftMetrics->open_shifts ?? 0),
                'opening_cash_total' => (int)($shiftMetrics->opening_cash_total ?? 0),
                'closing_cash_total' => (int)($shiftMetrics->closing_cash_total ?? 0),
                'diff_overage_total' => (int)($shiftMetrics->diff_overage_total ?? 0),
                'diff_shortage_total' => (int)($shiftMetrics->diff_shortage_total ?? 0),
            ],
            'growth' => $growth,
            'previous_period_summary' => $previousSnapshot,
            'top_clients' => $topClients,
            'daily' => $series,
        ];
    }

    public function resolveAllTimeStart(int $tenantId): Carbon
    {
        $candidates = [];
        $candidates[] = ClientTransaction::query()->where('tenant_id', $tenantId)->min('created_at');
        $candidates[] = Session::query()->where('tenant_id', $tenantId)->min('started_at');
        $candidates[] = Shift::query()->where('tenant_id', $tenantId)->min('opened_at');
        $candidates[] = ReturnRecord::query()->where('tenant_id', $tenantId)->min('created_at');
        $candidates[] = ShiftExpense::query()->where('tenant_id', $tenantId)->min('spent_at');
        $candidates[] = ShiftExpense::query()->where('tenant_id', $tenantId)->min('created_at');

        $candidates = array_filter($candidates);
        if (empty($candidates)) {
            return now()->subDays(30)->startOfDay();
        }

        $minDate = collect($candidates)->min();

        return Carbon::parse($minDate)->startOfDay();
    }

    public function compareKpis(array $leftReport, array $rightReport): array
    {
        $leftSummary = $leftReport['summary'] ?? [];
        $rightSummary = $rightReport['summary'] ?? [];
        $leftPayments = $leftReport['payments'] ?? [];
        $rightPayments = $rightReport['payments'] ?? [];
        $leftActivity = $leftReport['activity'] ?? [];
        $rightActivity = $rightReport['activity'] ?? [];

        $items = [
            [
                'key' => 'gross_sales',
                'label' => 'Gross sales',
                'unit' => 'UZS',
                'left' => (int)($leftSummary['gross_sales'] ?? 0),
                'right' => (int)($rightSummary['gross_sales'] ?? 0),
            ],
            [
                'key' => 'net_sales',
                'label' => 'Net sales',
                'unit' => 'UZS',
                'left' => (int)($leftSummary['net_sales'] ?? 0),
                'right' => (int)($rightSummary['net_sales'] ?? 0),
            ],
            [
                'key' => 'sessions_count',
                'label' => 'Sessions',
                'unit' => 'count',
                'left' => (int)($leftSummary['sessions_count'] ?? 0),
                'right' => (int)($rightSummary['sessions_count'] ?? 0),
            ],
            [
                'key' => 'avg_session_minutes',
                'label' => 'Avg session',
                'unit' => 'min',
                'left' => (float)($leftSummary['avg_session_minutes'] ?? 0),
                'right' => (float)($rightSummary['avg_session_minutes'] ?? 0),
            ],
            [
                'key' => 'utilization_pct',
                'label' => 'Utilization',
                'unit' => '%',
                'left' => (float)($leftSummary['utilization_pct'] ?? 0),
                'right' => (float)($rightSummary['utilization_pct'] ?? 0),
            ],
            [
                'key' => 'cash_net',
                'label' => 'Cash net',
                'unit' => 'UZS',
                'left' => (int)($leftPayments['cash_net'] ?? 0),
                'right' => (int)($rightPayments['cash_net'] ?? 0),
            ],
            [
                'key' => 'card_net',
                'label' => 'Card net',
                'unit' => 'UZS',
                'left' => (int)($leftPayments['card_net'] ?? 0),
                'right' => (int)($rightPayments['card_net'] ?? 0),
            ],
            [
                'key' => 'active_sessions_now',
                'label' => 'Active sessions now',
                'unit' => 'count',
                'left' => (int)($leftActivity['active_sessions_now'] ?? 0),
                'right' => (int)($rightActivity['active_sessions_now'] ?? 0),
            ],
            [
                'key' => 'pcs_online',
                'label' => 'Online PCs',
                'unit' => 'count',
                'left' => (int)($leftActivity['pcs_online'] ?? 0),
                'right' => (int)($rightActivity['pcs_online'] ?? 0),
            ],
        ];

        return array_map(function (array $item) {
            $left = (float)$item['left'];
            $right = (float)$item['right'];
            $diff = $right - $left;

            return array_merge($item, [
                'diff' => $diff,
                'diff_pct' => $left == 0.0 ? null : round(($diff / $left) * 100, 1),
                'winner' => $diff === 0.0 ? 'equal' : ($diff > 0 ? 'right' : 'left'),
            ]);
        }, $items);
    }

    private function buildKpiSnapshot(int $tenantId, Carbon $from, Carbon $to): array
    {
        $txBase = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to]);

        $grossSales = (clone $txBase)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type IN ('topup','package','subscription') THEN amount ELSE 0 END),0) AS gross_sales
            ")
            ->first();

        $tx = (object) array_merge(
            $this->rowToArray($grossSales),
            $this->rowToArray($this->payments->salesByTypeAndMethod(clone $txBase, ['topup', 'package', 'subscription'])),
        );

        $returnsBase = ReturnRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to]);

        $returns = (object) array_merge(
            $this->rowToArray((clone $returnsBase)->selectRaw("COALESCE(SUM(amount),0) AS returns_total")->first()),
            $this->rowToArray($this->payments->returnSummary(clone $returnsBase)),
        );
        $expenses = (int)ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('spent_at', [$from, $to])
                    ->orWhere(function ($qq) use ($from, $to) {
                        $qq->whereNull('spent_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->sum('amount');
        $session = Session::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$from, $to])
            ->selectRaw("
                COUNT(*) AS sessions_count,
                COALESCE(AVG(EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at))/60),0) AS avg_session_minutes,
                COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at))/60),0) AS total_session_minutes
            ")
            ->first();
        $pcsTotal = (int)Pc::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            })
            ->count();
        $periodMinutes = max(1, (int)$from->diffInMinutes($to));
        $capacityMinutes = $pcsTotal > 0 ? $pcsTotal * $periodMinutes : 0;
        $utilizationPct = $capacityMinutes > 0
            ? round(min(100, ((float)($session->total_session_minutes ?? 0) / $capacityMinutes) * 100), 1)
            : 0.0;
        $clientsInPeriod = (int)Session::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('client_id')
            ->distinct('client_id')
            ->count('client_id');
        $activeSessionsNow = (int)Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $grossSales = (int)($tx->gross_sales ?? 0);
        $returnsTotal = (int)($returns->returns_total ?? 0);
        $cashNet = (
            (int)($tx->cash_topup ?? 0) +
            (int)($tx->cash_package ?? 0) +
            (int)($tx->cash_subscription ?? 0)
        ) - (int)($returns->returns_cash_total ?? 0) - $expenses;
        $cardNet = (
            (int)($tx->card_topup ?? 0) +
            (int)($tx->card_package ?? 0) +
            (int)($tx->card_subscription ?? 0)
        ) - (int)($returns->returns_card_total ?? 0);

        return [
            'gross_sales' => $grossSales,
            'net_sales' => $grossSales - $returnsTotal,
            'sessions_count' => (int)($session->sessions_count ?? 0),
            'avg_session_minutes' => round((float)($session->avg_session_minutes ?? 0), 1),
            'utilization_pct' => $utilizationPct,
            'cash_net' => $cashNet,
            'card_net' => $cardNet,
            'active_sessions_now' => $activeSessionsNow,
            'clients_in_period' => $clientsInPeriod,
        ];
    }

    private function calcGrowthValue(float $current, float $previous): array
    {
        $diff = $current - $previous;

        return [
            'current' => $current,
            'previous' => $previous,
            'diff' => $diff,
            'diff_pct' => $previous == 0.0 ? null : round(($diff / $previous) * 100, 1),
            'trend' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'equal'),
        ];
    }

    private function formatRange(Carbon $from, Carbon $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $from->diffInDays($to) + 1,
        ];
    }

    private function rowToArray(mixed $row): array
    {
        if ($row instanceof \Illuminate\Database\Eloquent\Model) {
            return $row->getAttributes();
        }

        if (is_array($row)) {
            return $row;
        }

        if (is_object($row)) {
            return get_object_vars($row);
        }

        return [];
    }
}
