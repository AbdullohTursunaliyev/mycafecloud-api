<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\ClientTransaction;
use App\Models\LicenseKey;
use App\Models\Pc;
use App\Models\Promotion;
use App\Models\ReturnRecord;
use App\Models\Setting;
use App\Models\Session;
use App\Models\Shift;
use App\Models\ShiftExpense;
use App\Models\Tenant;
use App\Models\Zone;
use App\Service\EventLogger;
use App\Service\SettingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function cash(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['required','date'],
            'to' => ['required','date'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();

        $topups = ClientTransaction::where('tenant_id',$tenantId)
            ->where('type','topup')
            ->whereBetween('created_at',[$from,$to])
            ->selectRaw("
                SUM(CASE WHEN payment_method='cash' THEN amount ELSE 0 END) as cash,
                SUM(CASE WHEN payment_method='card' THEN amount ELSE 0 END) as card
            ")
            ->first();

        $packages = ClientTransaction::where('tenant_id',$tenantId)
            ->where('type','package')
            ->whereBetween('created_at',[$from,$to])
            ->selectRaw("
                SUM(CASE WHEN payment_method='cash' THEN amount ELSE 0 END) as cash,
                SUM(CASE WHEN payment_method='card' THEN amount ELSE 0 END) as card
            ")
            ->first();

        $subscriptions = ClientTransaction::where('tenant_id',$tenantId)
            ->where('type','subscription')
            ->whereBetween('created_at',[$from,$to])
            ->selectRaw("
                SUM(CASE WHEN payment_method='cash' THEN amount ELSE 0 END) as cash,
                SUM(CASE WHEN payment_method='card' THEN amount ELSE 0 END) as card
            ")
            ->first();

        return response()->json([
            'data' => [
                'topups' => [
                    'cash' => (int)($topups->cash ?? 0),
                    'card' => (int)($topups->card ?? 0),
                ],
                'packages' => [
                    'cash' => (int)($packages->cash ?? 0),
                    'card' => (int)($packages->card ?? 0),
                ],
                'subscriptions' => [
                    'cash' => (int)($subscriptions->cash ?? 0),
                    'card' => (int)($subscriptions->card ?? 0),
                ],
                'total_cash' =>
                    (int)($topups->cash ?? 0) +
                    (int)($packages->cash ?? 0) +
                    (int)($subscriptions->cash ?? 0),
                'total_card' =>
                    (int)($topups->card ?? 0) +
                    (int)($packages->card ?? 0) +
                    (int)($subscriptions->card ?? 0),
            ]
        ]);
    }

    public function sessions(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['required','date'],
            'to' => ['required','date'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();

        $rows = \App\Models\Session::where('tenant_id',$tenantId)
            ->whereBetween('started_at',[$from,$to])
            ->selectRaw("
                COUNT(*) as sessions_count,
                SUM(price_total) as revenue,
                AVG(EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at))/60) as avg_minutes
            ")
            ->first();

        return response()->json([
            'data' => [
                'sessions_count' => (int)$rows->sessions_count,
                'revenue' => (int)$rows->revenue,
                'avg_minutes' => round($rows->avg_minutes,1),
            ]
        ]);
    }

    public function topClients(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['required','date'],
            'to' => ['required','date'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();

        $clients = Session::where('tenant_id',$tenantId)
            ->whereBetween('started_at',[$from,$to])
            ->whereNotNull('client_id')
            ->select('client_id', DB::raw('SUM(price_total) as total_spent'), DB::raw('COUNT(*) as sessions_count'))
            ->groupBy('client_id')
            ->orderByDesc('total_spent')
            ->limit(10)
            ->get();

        $clientIds = $clients->pluck('client_id')->all();
        $clientsMap = Client::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $clientIds)
            ->get(['id','account_id','login','phone'])
            ->keyBy('id');

        $items = $clients->map(function ($row) use ($clientsMap) {
            $client = $clientsMap->get((int)$row->client_id);
            return [
                'client_id' => (int)$row->client_id,
                'total_spent' => (int)($row->total_spent ?? 0),
                'sessions_count' => (int)($row->sessions_count ?? 0),
                'client' => $client ? [
                    'id' => (int)$client->id,
                    'account_id' => $client->account_id,
                    'login' => $client->login,
                    'phone' => $client->phone,
                ] : null,
            ];
        })->values();

        return response()->json(['data'=>$items]);
    }

    public function overview(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;
        [$from, $to] = $this->resolveRange($request);

        $tenant = Tenant::query()->find($tenantId, ['id', 'name', 'status']);
        $report = $this->buildTenantReport($tenantId, $from, $to);

        return response()->json([
            'data' => [
                'tenant' => $tenant,
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'days' => $from->diffInDays($to) + 1,
                ],
                'report' => $report,
            ],
        ]);
    }

    public function aiInsights(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;
        if ($request->query('range') === 'all') {
            $from = $this->resolveAllTimeStart($tenantId);
            $to = now()->endOfDay();
        } else {
            [$from, $to] = $this->resolveRange($request);
        }

        $report = $this->buildTenantReport($tenantId, $from, $to);
        $summary = $report['summary'] ?? [];
        $payments = $report['payments'] ?? [];
        $activity = $report['activity'] ?? [];
        $sales = $report['sales'] ?? [];
        $clients = $report['clients'] ?? [];
        $sessions = $report['sessions'] ?? [];
        $zones = $report['zones'] ?? [];
        $operators = $report['operators'] ?? [];
        $pcs = $report['pcs'] ?? [];
        $shifts = $report['shifts'] ?? [];
        $growth = $report['growth'] ?? [];

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
        $totalPcs = 0;
        foreach ($zonePcRows as $row) {
            $zoneName = (string)$row->zone_name;
            $count = (int)$row->pcs_count;
            $zonePcMap[$zoneName] = $count;
            $totalPcs += $count;
        }

        $insights = [];
        $evidence = function (string $key, $value, ?string $unit = null): array {
            return [
                'key' => $key,
                'value' => $value,
                'unit' => $unit,
            ];
        };
        $add = function (
            string $id,
            string $type,
            int $priority,
            array $metrics = [],
            array $actions = [],
            string $impact = 'medium',
            int $confidence = 60,
            array $evidence = []
        ) use (&$insights) {
            $insights[] = [
                'id' => $id,
                'type' => $type,
                'priority' => $priority,
                'impact' => $impact,
                'confidence' => $confidence,
                'metrics' => $metrics,
                'evidence' => $evidence,
                'actions' => $actions,
            ];
        };

        $grossSales = (float)($summary['gross_sales'] ?? 0);
        $netSales = (float)($summary['net_sales'] ?? 0);
        $sessionsCount = (int)($summary['sessions_count'] ?? 0);
        $utilization = (float)($summary['utilization_pct'] ?? 0);
        $avgSessionMinutes = (float)($summary['avg_session_minutes'] ?? 0);
        $expensesTotal = (float)($summary['expenses_total'] ?? 0);
        $returnsTotal = (float)($summary['returns_total'] ?? 0);
        $returnsRatioPct = $grossSales > 0 ? round(($returnsTotal / $grossSales) * 100, 1) : 0.0;
        $expensesRatioPct = $grossSales > 0 ? round(($expensesTotal / $grossSales) * 100, 1) : 0.0;

        $cashSales = (float)($payments['cash_sales_total'] ?? 0);
        $cardSales = (float)($payments['card_sales_total'] ?? 0);
        $balanceSales = (float)($payments['balance_sales_total'] ?? 0);
        $paymentsTotal = $cashSales + $cardSales + $balanceSales;
        $cardSharePct = $paymentsTotal > 0 ? round(($cardSales / $paymentsTotal) * 100, 1) : 0.0;

        $topupTotal = (float)($sales['topup_total'] ?? 0);
        $packageTotal = (float)($sales['package_total'] ?? 0);
        $subscriptionTotal = (float)($sales['subscription_total'] ?? 0);
        $packageSharePct = $grossSales > 0 ? round((($packageTotal + $subscriptionTotal) / $grossSales) * 100, 1) : 0.0;

        $clientsInPeriod = (int)($activity['clients_in_period'] ?? 0);
        $newClients = (int)($clients['new_clients_in_period'] ?? 0);
        $newClientsRatioPct = $clientsInPeriod > 0 ? round(($newClients / $clientsInPeriod) * 100, 1) : 0.0;
        $returningClients = (int)($clients['returning_clients'] ?? 0);
        $returningClientsRatioPct = $clientsInPeriod > 0
            ? round(($returningClients / $clientsInPeriod) * 100, 1)
            : 0.0;
        $avgSessionsPerClient = (float)($sessions['avg_sessions_per_client'] ?? 0);
        $avgSessionRevenue = (float)($sessions['avg_session_revenue'] ?? 0);
        $avgTopupCheck = (float)($clients['avg_topup_check'] ?? 0);

        $activeSessionsNow = (int)($activity['active_sessions_now'] ?? 0);
        $pcsOnline = (int)($activity['pcs_online'] ?? 0);
        $liveOccupancyPct = $pcsOnline > 0 ? round(($activeSessionsNow / $pcsOnline) * 100, 1) : 0.0;

        $netSalesDiffPct = $growth['net_sales']['diff_pct'] ?? null;
        $sessionsDiffPct = $growth['sessions_count']['diff_pct'] ?? null;
        $returnsCount = (int)($summary['returns_count'] ?? 0);
        $txCount = (int)($summary['tx_count'] ?? 0);
        $returnsRatePct = $txCount > 0 ? round(($returnsCount / $txCount) * 100, 1) : 0.0;

        if ($utilization <= 30) {
            $add(
                'low_utilization',
                'risk',
                1,
                ['utilization_pct' => round($utilization, 1)],
                ['off_peak_promo', 'price_tune', 'marketing_push'],
                'high',
                $utilization <= 20 ? 85 : 70,
                [$evidence('utilization', round($utilization, 1), '%')]
            );
        } elseif ($utilization <= 50) {
            $add(
                'medium_utilization',
                'opportunity',
                3,
                ['utilization_pct' => round($utilization, 1)],
                ['off_peak_promo', 'bundle_push'],
                'medium',
                60,
                [$evidence('utilization', round($utilization, 1), '%')]
            );
        } elseif ($utilization >= 85) {
            $add(
                'high_utilization',
                'opportunity',
                3,
                ['utilization_pct' => round($utilization, 1)],
                ['upgrade_capacity', 'price_tune'],
                'medium',
                70,
                [$evidence('utilization', round($utilization, 1), '%')]
            );
        }

        if (is_numeric($netSalesDiffPct) && $netSalesDiffPct <= -10) {
            $add(
                'net_sales_drop',
                'risk',
                1,
                ['net_sales_diff_pct' => round((float)$netSalesDiffPct, 1)],
                ['off_peak_promo', 'marketing_push', 'price_tune'],
                'high',
                75,
                [
                    $evidence('net_sales', (int)$netSales, 'UZS'),
                    $evidence('growth', round((float)$netSalesDiffPct, 1), '%'),
                ]
            );
        } elseif (is_numeric($netSalesDiffPct) && $netSalesDiffPct >= 12) {
            $add(
                'net_sales_growth',
                'positive',
                5,
                ['net_sales_diff_pct' => round((float)$netSalesDiffPct, 1)],
                ['upgrade_capacity'],
                'medium',
                70,
                [
                    $evidence('net_sales', (int)$netSales, 'UZS'),
                    $evidence('growth', round((float)$netSalesDiffPct, 1), '%'),
                ]
            );
        }

        if (is_numeric($sessionsDiffPct) && $sessionsDiffPct <= -10) {
            $add(
                'sessions_drop',
                'risk',
                2,
                ['sessions_diff_pct' => round((float)$sessionsDiffPct, 1)],
                ['marketing_push', 'loyalty_push'],
                'high',
                70,
                [
                    $evidence('sessions', $sessionsCount, null),
                    $evidence('growth', round((float)$sessionsDiffPct, 1), '%'),
                ]
            );
        }

        if ($returnsRatioPct >= 5) {
            $add(
                'returns_high',
                'risk',
                2,
                ['returns_ratio_pct' => $returnsRatioPct],
                ['quality_check'],
                'high',
                70,
                [$evidence('returns', (int)$returnsTotal, 'UZS'), $evidence('returns_ratio', $returnsRatioPct, '%')]
            );
        }

        if ($expensesRatioPct >= 25) {
            $add(
                'expenses_high',
                'risk',
                2,
                ['expenses_ratio_pct' => $expensesRatioPct],
                ['cost_control'],
                'high',
                70,
                [$evidence('expenses', (int)$expensesTotal, 'UZS'), $evidence('expenses_ratio', $expensesRatioPct, '%')]
            );
        }

        if ($avgSessionMinutes > 0 && $avgSessionMinutes < 45) {
            $add(
                'short_sessions',
                'opportunity',
                3,
                ['avg_session_minutes' => round($avgSessionMinutes, 1)],
                ['bundle_push', 'loyalty_push'],
                'medium',
                60,
                [$evidence('avg_minutes', round($avgSessionMinutes, 1), 'min')]
            );
        }

        if ($grossSales > 0 && $packageSharePct < 15) {
            $add(
                'low_package_share',
                'opportunity',
                3,
                ['package_share_pct' => $packageSharePct],
                ['bundle_push'],
                'medium',
                60,
                [$evidence('package_share', $packageSharePct, '%')]
            );
        }

        if ($clientsInPeriod >= 20 && $newClientsRatioPct < 20) {
            $add(
                'low_new_clients',
                'opportunity',
                3,
                ['new_clients_ratio_pct' => $newClientsRatioPct],
                ['marketing_push', 'loyalty_push'],
                'medium',
                60,
                [$evidence('new_clients_ratio', $newClientsRatioPct, '%')]
            );
        }

        if ($clientsInPeriod >= 20 && ($returningClientsRatioPct < 35 || $avgSessionsPerClient < 1.3)) {
            $add(
                'low_repeat_rate',
                'opportunity',
                3,
                [
                    'returning_clients_ratio_pct' => $returningClientsRatioPct,
                    'avg_sessions_per_client' => round($avgSessionsPerClient, 2),
                ],
                ['loyalty_push', 'bundle_push', 'welcome_back_offer'],
                'medium',
                65,
                [
                    $evidence('returning_clients_ratio', $returningClientsRatioPct, '%'),
                    $evidence('avg_sessions_per_client', round($avgSessionsPerClient, 2), null),
                ]
            );
        }

        if ($paymentsTotal > 0 && $cardSharePct < 15) {
            $add(
                'cash_dominant',
                'opportunity',
                4,
                ['card_share_pct' => $cardSharePct],
                ['card_bonus'],
                'low',
                55,
                [$evidence('card_share', $cardSharePct, '%')]
            );
        }

        if ($returnsRatePct >= 6) {
            $add(
                'returns_count_high',
                'risk',
                2,
                ['returns_rate_pct' => $returnsRatePct],
                ['quality_check', 'staff_training'],
                'high',
                70,
                [
                    $evidence('returns_count', $returnsCount, null),
                    $evidence('returns_rate', $returnsRatePct, '%'),
                ]
            );
        }

        $underusedList = is_array($pcs['underused'] ?? null) ? $pcs['underused'] : [];
        $underusedCount = is_array($underusedList) ? count($underusedList) : 0;
        if ($underusedCount >= 5) {
            $add(
                'underused_pcs',
                'opportunity',
                4,
                ['underused_pcs_count' => $underusedCount],
                ['optimize_layout', 'price_tune'],
                'medium',
                60,
                [$evidence('underused_pcs', $underusedCount, null)]
            );
        }

        $shortageTotal = (float)($shifts['diff_shortage_total'] ?? 0);
        if ($shortageTotal > 0) {
            $add(
                'cash_shortage',
                'risk',
                2,
                ['shortage_total' => round($shortageTotal, 1)],
                ['quality_check', 'cost_control'],
                'high',
                80,
                [$evidence('shortage', round($shortageTotal, 1), 'UZS')]
            );
        }

        $peakHour = $sessions['peak_hour']['label'] ?? null;
        if ($peakHour) {
            $add(
                'peak_time',
                'info',
                5,
                ['peak_hour' => $peakHour],
                ['staff_peak'],
                'low',
                50,
                [$evidence('peak_hour', $peakHour, null)]
            );
        }

        $hourly = is_array($sessions['hourly_distribution'] ?? null) ? $sessions['hourly_distribution'] : [];
        if (!empty($hourly)) {
            $totalHourSessions = 0;
            $peakSessions = 0;
            foreach ($hourly as $row) {
                $count = (int)($row['sessions_count'] ?? 0);
                $totalHourSessions += $count;
                $peakSessions = max($peakSessions, $count);
            }
            $avgPerHour = count($hourly) > 0 ? $totalHourSessions / count($hourly) : 0;
            if ($avgPerHour > 0 && $peakSessions >= $avgPerHour * 2.5) {
                $add(
                    'peak_concentration',
                    'opportunity',
                    3,
                    ['peak_ratio' => round($peakSessions / $avgPerHour, 2)],
                    ['off_peak_promo', 'price_tune'],
                    'medium',
                    65,
                    [$evidence('peak_hour', $peakHour, null), $evidence('sessions', $peakSessions, null)]
                );
            }
        }

        $weekday = is_array($sessions['weekday_distribution'] ?? null) ? $sessions['weekday_distribution'] : [];
        if (!empty($weekday)) {
            $weekdayTotal = 0;
            $peakWeekday = null;
            foreach ($weekday as $row) {
                $count = (int)($row['sessions_count'] ?? 0);
                $weekdayTotal += $count;
                if ($peakWeekday === null || $count > (int)($peakWeekday['sessions_count'] ?? 0)) {
                    $peakWeekday = $row;
                }
            }
            $peakWeekdayCount = (int)($peakWeekday['sessions_count'] ?? 0);
            $peakWeekdayShare = $weekdayTotal > 0 ? round(($peakWeekdayCount / $weekdayTotal) * 100, 1) : 0.0;
            if ($weekdayTotal >= 30 && $peakWeekdayShare >= 40) {
                $add(
                    'weekday_imbalance',
                    'opportunity',
                    3,
                    ['peak_weekday_share_pct' => $peakWeekdayShare],
                    ['weekday_promo', 'off_peak_promo', 'marketing_push'],
                    'medium',
                    65,
                    [
                        $evidence('peak_weekday', $peakWeekday['label'] ?? '-', null),
                        $evidence('peak_weekday_share', $peakWeekdayShare, '%'),
                    ]
                );
            }
        }

        $zonesItems = is_array($zones['items'] ?? null) ? $zones['items'] : [];
        if (!empty($zonesItems)) {
            $totalRevenue = 0;
            $topZone = null;
            $lowZone = null;
            foreach ($zonesItems as $row) {
                $rev = (int)($row['revenue'] ?? 0);
                $totalRevenue += $rev;
                if ($topZone === null || $rev > (int)($topZone['revenue'] ?? 0)) {
                    $topZone = $row;
                }
                if ($lowZone === null || $rev < (int)($lowZone['revenue'] ?? 0)) {
                    $lowZone = $row;
                }
            }
            if ($totalRevenue > 0 && $topZone) {
                $topShare = round(((int)($topZone['revenue'] ?? 0) / $totalRevenue) * 100, 1);
                if ($topShare >= 60) {
                    $add(
                        'zone_imbalance',
                        'opportunity',
                        3,
                        ['top_zone_share_pct' => $topShare],
                        ['price_tune', 'optimize_layout'],
                        'medium',
                        65,
                        [$evidence('top_zone', $topZone['zone'] ?? '-', null), $evidence('top_zone_share', $topShare, '%')]
                    );
                }
            }
            if ($totalRevenue > 0 && $topZone && $lowZone && ($topZone['zone'] ?? '') !== ($lowZone['zone'] ?? '')) {
                $topShare = round(((int)($topZone['revenue'] ?? 0) / $totalRevenue) * 100, 1);
                $lowShare = round(((int)($lowZone['revenue'] ?? 0) / $totalRevenue) * 100, 1);
                $gap = max(0.0, $topShare - $lowShare);
                $topZoneName = (string)($topZone['zone'] ?? '-');
                $lowZoneName = (string)($lowZone['zone'] ?? '-');
                $topZonePcs = $zonePcMap[$topZoneName] ?? 0;
                $lowZonePcs = $zonePcMap[$lowZoneName] ?? 0;
                $ratio = $gap > 0 ? min(0.25, max(0.05, $gap / 200)) : 0.05;
                $movePcs = $totalPcs > 0 ? max(1, (int)round($totalPcs * $ratio)) : 1;
                $impact = $gap >= 25 ? 'high' : ($gap >= 12 ? 'medium' : 'low');
                $priority = $gap >= 25 ? 2 : ($gap >= 12 ? 3 : 4);
                $confidence = $gap >= 25 ? 75 : ($gap >= 12 ? 65 : 55);
                $add(
                    'zone_rebalance',
                    'opportunity',
                    $priority,
                    [
                        'top_zone_share_pct' => $topShare,
                        'low_zone_share_pct' => $lowShare,
                        'move_pcs' => $movePcs,
                    ],
                    ['add_pcs_top_zone', 'reduce_pcs_low_zone', 'optimize_layout'],
                    $impact,
                    $confidence,
                    [
                        $evidence('top_zone', $topZoneName, null),
                        $evidence('top_zone_share', $topShare, '%'),
                        $evidence('top_zone_revenue', (int)($topZone['revenue'] ?? 0), 'UZS'),
                        $evidence('top_zone_pcs', (int)$topZonePcs, null),
                        $evidence('low_zone', $lowZoneName, null),
                        $evidence('low_zone_share', $lowShare, '%'),
                        $evidence('low_zone_revenue', (int)($lowZone['revenue'] ?? 0), 'UZS'),
                        $evidence('low_zone_pcs', (int)$lowZonePcs, null),
                        $evidence('move_pcs', $movePcs, null),
                    ]
                );
            }
        }

        $operatorsItems = is_array($operators['items'] ?? null) ? $operators['items'] : [];
        if (count($operatorsItems) >= 3) {
            $totalSales = 0;
            $topOperator = null;
            foreach ($operatorsItems as $row) {
                $salesAmount = (int)($row['sales_amount'] ?? 0) + (int)($row['sessions_revenue'] ?? 0);
                $totalSales += $salesAmount;
                if ($topOperator === null || $salesAmount > (int)($topOperator['sales_amount'] ?? 0) + (int)($topOperator['sessions_revenue'] ?? 0)) {
                    $topOperator = $row;
                }
            }
            $avgSales = $totalSales / max(1, count($operatorsItems));
            if ($topOperator && $avgSales > 0) {
                $topSales = (int)($topOperator['sales_amount'] ?? 0) + (int)($topOperator['sessions_revenue'] ?? 0);
                if ($topSales >= $avgSales * 2.5) {
                    $add(
                        'operator_variance',
                        'opportunity',
                        4,
                        ['top_operator_share_pct' => round(($topSales / $totalSales) * 100, 1)],
                        ['staff_training', 'staff_peak'],
                        'medium',
                        60,
                        [$evidence('operators', $topOperator['operator'] ?? '-', null), $evidence('revenue', $topSales, 'UZS')]
                    );
                }
            }
        }

        $bonusTotal = (float)($sales['topup_bonus_total'] ?? 0) + (float)($sales['tier_bonus_total'] ?? 0);
        $bonusRatioPct = $grossSales > 0 ? round(($bonusTotal / $grossSales) * 100, 1) : 0.0;
        if ($bonusRatioPct >= 15) {
            $add(
                'bonus_high',
                'opportunity',
                4,
                ['bonus_ratio_pct' => $bonusRatioPct],
                ['cost_control', 'loyalty_push'],
                'medium',
                60,
                [$evidence('bonus', (int)$bonusTotal, 'UZS'), $evidence('bonus_ratio', $bonusRatioPct, '%')]
            );
        }

        if ($avgSessionRevenue > 0 && $avgTopupCheck > 0 && $avgSessionRevenue < ($avgTopupCheck * 0.5)) {
            $add(
                'low_session_value',
                'opportunity',
                4,
                ['avg_session_revenue' => round($avgSessionRevenue, 1)],
                ['bundle_push', 'price_tune'],
                'low',
                55,
                [
                    $evidence('avg_session_revenue', round($avgSessionRevenue, 1), 'UZS'),
                    $evidence('avg_topup_check', round($avgTopupCheck, 1), 'UZS'),
                ]
            );
        }

        $typeWeight = [
            'risk' => 0,
            'opportunity' => 1,
            'positive' => 2,
            'info' => 3,
        ];
        usort($insights, function ($a, $b) use ($typeWeight) {
            $pa = (int)($a['priority'] ?? 5);
            $pb = (int)($b['priority'] ?? 5);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $ta = $typeWeight[$a['type'] ?? 'info'] ?? 9;
            $tb = $typeWeight[$b['type'] ?? 'info'] ?? 9;
            return $ta <=> $tb;
        });

        return response()->json([
            'data' => [
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'days' => $from->diffInDays($to) + 1,
                ],
                'kpis' => [
                    'gross_sales' => (int)$grossSales,
                    'net_sales' => (int)$netSales,
                    'sessions_count' => $sessionsCount,
                    'utilization_pct' => round($utilization, 1),
                    'avg_session_minutes' => round($avgSessionMinutes, 1),
                    'returns_ratio_pct' => $returnsRatioPct,
                    'expenses_ratio_pct' => $expensesRatioPct,
                    'package_share_pct' => $packageSharePct,
                    'new_clients_ratio_pct' => $newClientsRatioPct,
                    'card_share_pct' => $cardSharePct,
                    'live_occupancy_pct' => $liveOccupancyPct,
                    'active_sessions_now' => $activeSessionsNow,
                    'pcs_online' => $pcsOnline,
                    'cash_sales_total' => (int)$cashSales,
                    'card_sales_total' => (int)$cardSales,
                    'balance_sales_total' => (int)$balanceSales,
                    'topup_total' => (int)$topupTotal,
                ],
                'insights' => $insights,
            ],
        ]);
    }

    public function lostRevenue(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;
        [$from, $to] = $this->resolveRange($request);

        $report = $this->buildTenantReport($tenantId, $from, $to);
        $summary = $report['summary'] ?? [];
        $sessions = $report['sessions'] ?? [];
        $activity = $report['activity'] ?? [];

        $netSales = (float)($summary['net_sales'] ?? 0);
        $totalMinutes = (float)($sessions['total_minutes'] ?? 0);
        $pcsTotal = max(1, (int)($activity['pcs_total'] ?? 0));
        $periodMinutes = max(1, (int)$from->diffInMinutes($to));
        $capacityMinutes = $pcsTotal * $periodMinutes;
        $revenuePerMinute = $totalMinutes > 0 ? $netSales / $totalMinutes : 0.0;
        $potentialRevenue = $capacityMinutes > 0 ? $revenuePerMinute * $capacityMinutes : 0.0;
        $lostRevenue = max(0.0, $potentialRevenue - $netSales);
        $lostPct = $potentialRevenue > 0 ? round(($lostRevenue / $potentialRevenue) * 100, 1) : 0.0;
        $utilization = $capacityMinutes > 0 ? round(min(100, ($totalMinutes / $capacityMinutes) * 100), 1) : 0.0;

        return response()->json([
            'data' => [
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'days' => $from->diffInDays($to) + 1,
                ],
                'metrics' => [
                    'pcs_total' => $pcsTotal,
                    'capacity_minutes' => (int)round($capacityMinutes),
                    'used_minutes' => (int)round($totalMinutes),
                    'utilization_pct' => $utilization,
                    'net_sales' => (int)$netSales,
                    'revenue_per_minute' => round($revenuePerMinute, 2),
                    'potential_revenue' => (int)round($potentialRevenue),
                    'lost_revenue' => (int)round($lostRevenue),
                    'lost_revenue_pct' => $lostPct,
                ],
            ],
        ]);
    }

    public function zoneProfitability(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;
        [$from, $to] = $this->resolveRange($request);

        $report = $this->buildTenantReport($tenantId, $from, $to);
        $zonesItems = (array)($report['zones']['items'] ?? []);

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
            $zonePcMap[(string)$row->zone_name] = (int)$row->pcs_count;
        }

        $items = [];
        $zoneMap = [];
        foreach ($zonesItems as $row) {
            $zoneName = (string)($row['zone'] ?? 'No zone');
            $zoneMap[$zoneName] = $row;
        }
        $allZoneNames = array_unique(array_merge(array_keys($zoneMap), array_keys($zonePcMap)));
        foreach ($allZoneNames as $zoneName) {
            $row = $zoneMap[$zoneName] ?? [];
            $revenue = (int)($row['revenue'] ?? 0);
            $sessionsCount = (int)($row['sessions_count'] ?? 0);
            $minutes = (int)($row['minutes'] ?? 0);
            $pcsCount = (int)($zonePcMap[$zoneName] ?? 0);
            $revPerPc = $pcsCount > 0 ? round($revenue / $pcsCount, 1) : 0.0;
            $revPerSession = $sessionsCount > 0 ? round($revenue / $sessionsCount, 1) : 0.0;
            $items[] = [
                'zone' => $zoneName,
                'pcs_count' => $pcsCount,
                'revenue' => $revenue,
                'sessions_count' => $sessionsCount,
                'minutes' => $minutes,
                'revenue_per_pc' => $revPerPc,
                'revenue_per_session' => $revPerSession,
            ];
        }

        usort($items, function ($a, $b) {
            return ($b['revenue_per_pc'] <=> $a['revenue_per_pc']);
        });

        return response()->json([
            'data' => [
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'days' => $from->diffInDays($to) + 1,
                ],
                'zones' => $items,
            ],
        ]);
    }

    public function abCompare(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;

        $payload = $request->validate([
            'from_a' => ['required', 'date'],
            'to_a' => ['required', 'date', 'after_or_equal:from_a'],
            'from_b' => ['required', 'date'],
            'to_b' => ['required', 'date', 'after_or_equal:from_b'],
        ]);

        $fromA = Carbon::parse($payload['from_a'])->startOfDay();
        $toA = Carbon::parse($payload['to_a'])->endOfDay();
        $fromB = Carbon::parse($payload['from_b'])->startOfDay();
        $toB = Carbon::parse($payload['to_b'])->endOfDay();

        $reportA = $this->buildTenantReport($tenantId, $fromA, $toA);
        $reportB = $this->buildTenantReport($tenantId, $fromB, $toB);

        $salesA = $reportA['sales'] ?? [];
        $salesB = $reportB['sales'] ?? [];
        $summaryA = $reportA['summary'] ?? [];
        $summaryB = $reportB['summary'] ?? [];

        $metrics = [
            'net_sales' => [(int)($summaryA['net_sales'] ?? 0), (int)($summaryB['net_sales'] ?? 0)],
            'gross_sales' => [(int)($summaryA['gross_sales'] ?? 0), (int)($summaryB['gross_sales'] ?? 0)],
            'sessions_count' => [(int)($summaryA['sessions_count'] ?? 0), (int)($summaryB['sessions_count'] ?? 0)],
            'avg_session_minutes' => [(float)($summaryA['avg_session_minutes'] ?? 0), (float)($summaryB['avg_session_minutes'] ?? 0)],
            'topup_total' => [(int)($salesA['topup_total'] ?? 0), (int)($salesB['topup_total'] ?? 0)],
            'package_total' => [(int)($salesA['package_total'] ?? 0), (int)($salesB['package_total'] ?? 0)],
            'subscription_total' => [(int)($salesA['subscription_total'] ?? 0), (int)($salesB['subscription_total'] ?? 0)],
        ];

        $diff = [];
        foreach ($metrics as $key => [$a, $b]) {
            $delta = $b - $a;
            $pct = $a > 0 ? round(($delta / $a) * 100, 1) : null;
            $diff[$key] = [
                'a' => $a,
                'b' => $b,
                'delta' => $delta,
                'delta_pct' => $pct,
            ];
        }

        return response()->json([
            'data' => [
                'range_a' => [
                    'from' => $fromA->toDateString(),
                    'to' => $toA->toDateString(),
                ],
                'range_b' => [
                    'from' => $fromB->toDateString(),
                    'to' => $toB->toDateString(),
                ],
                'metrics' => $diff,
            ],
        ]);
    }

    public function monthlyPdf(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;
        $month = (string)$request->query('month', now()->format('Y-m'));

        try {
            $monthDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'month' => 'Month must be in YYYY-MM format',
            ]);
        }
        $from = $monthDate->copy()->startOfMonth();
        $to = $monthDate->copy()->endOfMonth();

        $report = $this->buildTenantReport($tenantId, $from, $to);
        $summary = $report['summary'] ?? [];
        $sessions = $report['sessions'] ?? [];
        $payments = $report['payments'] ?? [];
        $zones = $report['zones']['top_zone'] ?? null;

        $tenant = Tenant::query()->find($tenantId, ['id', 'name']);
        $clubName = $tenant?->name ?? ('Club #' . $tenantId);

        $lines = [
            'MyCafe Owner Monthly Summary',
            'Club: ' . $clubName,
            'Period: ' . $from->toDateString() . ' -> ' . $to->toDateString(),
            'Net sales: ' . (int)($summary['net_sales'] ?? 0) . ' UZS',
            'Gross sales: ' . (int)($summary['gross_sales'] ?? 0) . ' UZS',
            'Sessions: ' . (int)($summary['sessions_count'] ?? 0),
            'Avg session: ' . (float)($summary['avg_session_minutes'] ?? 0) . ' min',
            'Utilization: ' . (float)($summary['utilization_pct'] ?? 0) . ' %',
            'Returns: ' . (int)($summary['returns_total'] ?? 0) . ' UZS',
            'Expenses: ' . (int)($summary['expenses_total'] ?? 0) . ' UZS',
            'Cash sales: ' . (int)($payments['cash_sales_total'] ?? 0) . ' UZS',
            'Card sales: ' . (int)($payments['card_sales_total'] ?? 0) . ' UZS',
            'Balance sales: ' . (int)($payments['balance_sales_total'] ?? 0) . ' UZS',
        ];
        if ($zones) {
            $lines[] = 'Top zone: ' . ($zones['zone'] ?? '-') . ' | ' . (int)($zones['revenue'] ?? 0) . ' UZS';
        }

        $pdf = $this->buildSimplePdf($lines);
        $filename = 'monthly-summary-' . $monthDate->format('Y-m') . '.pdf';

        return response()->json([
            'data' => [
                'month' => $monthDate->format('Y-m'),
                'filename' => $filename,
                'pdf_base64' => base64_encode($pdf),
            ],
        ]);
    }

    private function buildSimplePdf(array $lines): string
    {
        $escape = function (string $text): string {
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        };

        $y = 780;
        $content = "BT\n/F1 12 Tf\n";
        foreach ($lines as $line) {
            $content .= "1 0 0 1 50 {$y} Tm (" . $escape((string)$line) . ") Tj\n";
            $y -= 16;
            if ($y < 40) {
                break;
            }
        }
        $content .= "ET";

        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[5] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "{$i} 0 obj\n{$obj}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function resolveAllTimeStart(int $tenantId): Carbon
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

    public function branchCompare(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;

        $payload = $request->validate([
            'license_key' => ['required', 'string', 'max:120'],
        ]);
        [$from, $to] = $this->resolveRange($request);

        $license = LicenseKey::query()
            ->with('tenant:id,name,status')
            ->where('key', $payload['license_key'])
            ->first();

        if (!$license || !$license->tenant_id) {
            throw ValidationException::withMessages([
                'license_key' => 'License key not found',
            ]);
        }

        if ((int)$license->tenant_id === $tenantId) {
            throw ValidationException::withMessages([
                'license_key' => 'Enter another branch license key',
            ]);
        }

        $leftTenant = Tenant::query()->find($tenantId, ['id', 'name', 'status']);
        $rightTenant = $license->tenant
            ? $license->tenant->only(['id', 'name', 'status'])
            : ['id' => (int)$license->tenant_id, 'name' => '-', 'status' => 'unknown'];

        $leftReport = $this->buildTenantReport($tenantId, $from, $to);
        $rightReport = $this->buildTenantReport((int)$license->tenant_id, $from, $to);

        return response()->json([
            'data' => [
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'days' => $from->diffInDays($to) + 1,
                ],
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
            ],
        ]);
    }

    public function autopilot(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;
        [$from, $to] = $this->resolveRange($request);

        $strategy = strtolower((string)$request->query('strategy', 'balanced'));
        if (!in_array($strategy, ['balanced', 'growth', 'aggressive'], true)) {
            throw ValidationException::withMessages([
                'strategy' => 'Strategy must be one of: balanced, growth, aggressive',
            ]);
        }

        $plan = $this->buildAutopilotPlan($tenantId, $from, $to, $strategy);

        return response()->json([
            'data' => $plan,
        ]);
    }

    public function autopilotApply(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;

        $payload = $request->validate([
            'strategy' => ['nullable', 'string', 'in:balanced,growth,aggressive'],
            'apply_zone_prices' => ['nullable', 'boolean'],
            'apply_promotion' => ['nullable', 'boolean'],
            'enable_beast_mode' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $strategy = strtolower((string)($payload['strategy'] ?? 'balanced'));
        $applyZonePrices = array_key_exists('apply_zone_prices', $payload) ? (bool)$payload['apply_zone_prices'] : true;
        $applyPromotion = array_key_exists('apply_promotion', $payload) ? (bool)$payload['apply_promotion'] : true;
        $enableBeastMode = array_key_exists('enable_beast_mode', $payload) ? (bool)$payload['enable_beast_mode'] : true;
        $dryRun = (bool)($payload['dry_run'] ?? false);

        [$from, $to] = $this->resolveRange($request);
        $plan = $this->buildAutopilotPlan($tenantId, $from, $to, $strategy);

        if ($dryRun) {
            return response()->json([
                'data' => [
                    'applied' => false,
                    'dry_run' => true,
                    'strategy' => $strategy,
                    'plan' => $plan,
                ],
            ]);
        }

        $appliedZoneUpdates = [];
        $appliedPromotionResult = null;
        $appliedBeastModeState = null;
        $zoneUpdates = $plan['actions']['zone_price_updates'] ?? [];
        $promotionPlan = $plan['actions']['promotion'] ?? null;
        $beastPlan = $plan['beast_mode'] ?? [];

        DB::transaction(function () use (
            $tenantId,
            $applyZonePrices,
            $applyPromotion,
            $enableBeastMode,
            $zoneUpdates,
            $promotionPlan,
            $beastPlan,
            &$appliedZoneUpdates,
            &$appliedPromotionResult,
            &$appliedBeastModeState
        ) {
            if ($applyZonePrices) {
                foreach ($zoneUpdates as $item) {
                    $zone = Zone::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey((int)($item['zone_id'] ?? 0))
                        ->lockForUpdate()
                        ->first();

                    if (!$zone) {
                        continue;
                    }

                    $oldPrice = (int)$zone->price_per_hour;
                    $newPrice = (int)($item['recommended_price_per_hour'] ?? $oldPrice);

                    if ($newPrice <= 0 || $newPrice === $oldPrice) {
                        continue;
                    }

                    $zone->price_per_hour = $newPrice;
                    $zone->save();

                    $appliedZoneUpdates[] = [
                        'zone_id' => (int)$zone->id,
                        'zone_name' => (string)$zone->name,
                        'old_price_per_hour' => $oldPrice,
                        'new_price_per_hour' => $newPrice,
                        'delta_pct' => $oldPrice > 0 ? round((($newPrice - $oldPrice) / $oldPrice) * 100, 1) : null,
                    ];
                }
            }

            if ($applyPromotion && is_array($promotionPlan) && !empty($promotionPlan['enabled'])) {
                $promoPayload = [
                    'name' => (string)($promotionPlan['name'] ?? 'AI Booster'),
                    'type' => 'double_topup',
                    'is_active' => true,
                    'days_of_week' => !empty($promotionPlan['days_of_week']) ? array_values($promotionPlan['days_of_week']) : null,
                    'time_from' => !empty($promotionPlan['time_from']) ? (string)$promotionPlan['time_from'] : null,
                    'time_to' => !empty($promotionPlan['time_to']) ? (string)$promotionPlan['time_to'] : null,
                    'applies_payment_method' => 'cash',
                    'starts_at' => !empty($promotionPlan['starts_at']) ? Carbon::parse($promotionPlan['starts_at']) : null,
                    'ends_at' => !empty($promotionPlan['ends_at']) ? Carbon::parse($promotionPlan['ends_at']) : null,
                    'priority' => (int)($promotionPlan['priority'] ?? 5),
                ];

                $existing = Promotion::query()
                    ->where('tenant_id', $tenantId)
                    ->where('type', 'double_topup')
                    ->where('name', $promoPayload['name'])
                    ->orderByDesc('id')
                    ->first();

                if ($existing) {
                    $existing->fill($promoPayload);
                    $existing->save();

                    $appliedPromotionResult = [
                        'id' => (int)$existing->id,
                        'action' => 'updated',
                        'name' => (string)$existing->name,
                        'time_from' => $existing->time_from,
                        'time_to' => $existing->time_to,
                    ];
                } else {
                    $created = Promotion::query()->create(array_merge($promoPayload, [
                        'tenant_id' => $tenantId,
                    ]));

                    $appliedPromotionResult = [
                        'id' => (int)$created->id,
                        'action' => 'created',
                        'name' => (string)$created->name,
                        'time_from' => $created->time_from,
                        'time_to' => $created->time_to,
                    ];
                }
            }

            $policy = [
                'enabled' => $enableBeastMode,
                'strategy' => (string)($beastPlan['strategy'] ?? 'balanced'),
                'profit_guard' => $beastPlan['profit_guard'] ?? null,
                'energy_optimizer' => $beastPlan['energy_optimizer'] ?? null,
                'leak_watch' => $beastPlan['leak_watch'] ?? null,
                'updated_at' => now()->toDateTimeString(),
            ];
            SettingService::set($tenantId, 'beast_mode', $policy);
            $appliedBeastModeState = $policy;
        });

        try {
            EventLogger::log(
                $tenantId,
                'autopilot_applied',
                'operator',
                'operator',
                (int)$operator->id,
                [
                    'strategy' => $strategy,
                    'zones_updated_count' => count($appliedZoneUpdates),
                    'promotion_applied' => $appliedPromotionResult !== null,
                    'beast_mode_enabled' => (bool)($appliedBeastModeState['enabled'] ?? false),
                ]
            );
        } catch (\Throwable $e) {
            // Do not fail the request if logging fails.
        }

        return response()->json([
            'data' => [
                'applied' => true,
                'strategy' => $strategy,
                'zone_updates' => $appliedZoneUpdates,
                'promotion' => $appliedPromotionResult,
                'summary' => [
                    'zones_updated_count' => count($appliedZoneUpdates),
                    'promotion_applied' => $appliedPromotionResult !== null,
                    'beast_mode_enabled' => (bool)($appliedBeastModeState['enabled'] ?? false),
                ],
                'beast_mode' => $appliedBeastModeState,
                'plan' => $plan,
            ],
        ]);
    }

    public function exchange(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;
        [$from, $to] = $this->resolveRange($request);

        $report = $this->buildTenantReport($tenantId, $from, $to);
        $tenant = Tenant::query()->find($tenantId, ['id', 'name', 'status']);

        $config = $this->normalizeExchangeConfig(
            (array)SettingService::get($tenantId, 'exchange_network', $this->defaultExchangeConfig())
        );

        $selfSettings = Setting::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('key', ['club_name', 'club_location'])
            ->get()
            ->keyBy('key');

        $selfClubName = (string)(
            optional($selfSettings->get('club_name'))->value
            ?: ($tenant->name ?? ('Club #' . $tenantId))
        );
        $selfLocation = $this->parseClubLocation(optional($selfSettings->get('club_location'))->value);

        $selfActivity = (array)($report['activity'] ?? []);
        $selfOnline = max(0, (int)($selfActivity['pcs_online'] ?? 0));
        $selfActive = max(0, (int)($selfActivity['active_sessions_now'] ?? 0));
        $selfFree = max(0, $selfOnline - $selfActive);
        $selfLoad = $selfOnline > 0 ? round(($selfActive / max(1, $selfOnline)) * 100, 1) : 0.0;

        $partnerTenants = Tenant::query()
            ->where('id', '!=', $tenantId)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('license_keys')
                    ->whereColumn('license_keys.tenant_id', 'tenants.id')
                    ->where('license_keys.status', 'active')
                    ->where(function ($qq) {
                        $qq->whereNull('license_keys.expires_at')
                            ->orWhere('license_keys.expires_at', '>', now());
                    });
            })
            ->orderBy('id')
            ->limit(60)
            ->get(['id', 'name', 'status']);

        $partnerIds = $partnerTenants->pluck('id')->all();
        $partnerSettings = Setting::query()
            ->whereIn('tenant_id', $partnerIds)
            ->whereIn('key', ['club_name', 'club_location', 'exchange_network'])
            ->get()
            ->groupBy('tenant_id');

        $onlineSince = now()->subMinutes(3);
        $partnerPcRows = Pc::query()
            ->whereIn('tenant_id', $partnerIds)
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            })
            ->selectRaw(
                "tenant_id, COUNT(*) as pcs_total, SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) as pcs_online",
                [$onlineSince]
            )
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $partnerSessionRows = Session::query()
            ->whereIn('tenant_id', $partnerIds)
            ->where('status', 'active')
            ->selectRaw('tenant_id, COUNT(*) as active_sessions')
            ->groupBy('tenant_id')
            ->pluck('active_sessions', 'tenant_id');

        $partners = [];
        $disabledCandidates = 0;
        foreach ($partnerTenants as $p) {
            $pid = (int)$p->id;
            $setMap = collect($partnerSettings->get($pid, []))->keyBy('key');
            $pCfg = $this->normalizeExchangeConfig(
                (array)(optional($setMap->get('exchange_network'))->value ?? $this->defaultExchangeConfig())
            );
            $pClubName = (string)(optional($setMap->get('club_name'))->value ?: $p->name);
            $pLocation = $this->parseClubLocation(optional($setMap->get('club_location'))->value);

            $pc = $partnerPcRows->get($pid);
            $pcsTotal = max(0, (int)($pc->pcs_total ?? 0));
            $pcsOnline = max(0, (int)($pc->pcs_online ?? 0));
            $activeSessions = max(0, (int)($partnerSessionRows[$pid] ?? 0));
            $freePcs = max(0, $pcsOnline - $activeSessions);
            $loadPct = $pcsOnline > 0 ? round(($activeSessions / max(1, $pcsOnline)) * 100, 1) : 0.0;

            $distanceKm = null;
            if ($selfLocation && $pLocation) {
                $distanceKm = $this->distanceKm(
                    (float)$selfLocation['lat'],
                    (float)$selfLocation['lng'],
                    (float)$pLocation['lat'],
                    (float)$pLocation['lng']
                );
            }

            $withinRadius = $distanceKm === null || $distanceKm <= (float)$config['radius_km'];
            $canReceive = (bool)$pCfg['enabled']
                && $withinRadius
                && $freePcs >= max(1, (int)$pCfg['min_free_pcs']);

            if (!(bool)$pCfg['enabled']) {
                $disabledCandidates++;
            }

            $score = ($freePcs * 9) + (100 - $loadPct);
            if ($distanceKm !== null) {
                $score -= (float)$distanceKm * 1.3;
            }
            if (!$canReceive) {
                $score -= 24;
            }
            $score = round($score, 1);

            $suggestedBid = $this->clampInt(
                (int)round(
                    (int)$config['auction_floor_uzs']
                    + (max(0, 45 - (int)$loadPct) * 260)
                ),
                (int)$config['auction_floor_uzs'],
                (int)$config['auction_ceiling_uzs']
            );

            $partners[] = [
                'tenant_id' => $pid,
                'club_name' => $pClubName,
                'status' => (string)$p->status,
                'exchange_enabled' => (bool)$pCfg['enabled'],
                'distance_km' => $distanceKm === null ? null : round($distanceKm, 1),
                'within_radius' => $withinRadius,
                'pcs_total' => $pcsTotal,
                'pcs_online' => $pcsOnline,
                'active_sessions' => $activeSessions,
                'free_pcs' => $freePcs,
                'load_pct' => $loadPct,
                'can_receive' => $canReceive,
                'score' => $score,
                'suggested_bid_uzs' => $suggestedBid,
            ];
        }

        $partners = collect($partners)
            ->sortByDesc(function ($row) {
                $row = is_array($row) ? $row : [];
                $base = (float)($row['score'] ?? 0);
                $bonus = !empty($row['can_receive']) ? 100000 : 0;
                return $bonus + $base;
            })
            ->values();

        $topTargets = $partners->where('can_receive', true)->take(3)->values();
        $overflowNeed = $selfLoad >= 88 && $selfFree < max(1, (int)$config['min_free_pcs']);
        $overflowDemandPcs = $overflowNeed ? max(1, (int)ceil(max(0, $selfLoad - 82) / 4)) : 0;
        $availableInTargets = (int)$topTargets->sum('free_pcs');
        $reroutePcs = min($overflowDemandPcs, $availableInTargets);

        $summary = (array)($report['summary'] ?? []);
        $avgRevPerSession = (int)($summary['sessions_count'] ?? 0) > 0
            ? round(((int)($summary['net_sales'] ?? 0)) / max(1, (int)$summary['sessions_count']), 1)
            : 0.0;
        $projectedRecoveredMonthly = (int)round($reroutePcs * $avgRevPerSession * 40);

        $identityBase = ClientMembership::query()->where('tenant_id', $tenantId);
        $membersTotal = (int)(clone $identityBase)->distinct('identity_id')->count('identity_id');
        $crossClubMembers = (int)(clone $identityBase)
            ->whereIn('identity_id', function ($q) {
                $q->from('client_memberships')
                    ->select('identity_id')
                    ->groupBy('identity_id')
                    ->havingRaw('COUNT(DISTINCT tenant_id) > 1');
            })
            ->distinct('identity_id')
            ->count('identity_id');

        $auctionBid = $this->clampInt(
            (int)round(
                (int)$config['auction_floor_uzs']
                + (max(0, ((int)$selfLoad - 70)) * 300)
            ),
            (int)$config['auction_floor_uzs'],
            (int)$config['auction_ceiling_uzs']
        );

        return response()->json([
            'data' => [
                'generated_at' => now()->toDateTimeString(),
                'club' => [
                    'tenant_id' => $tenantId,
                    'name' => $selfClubName,
                    'load_pct' => $selfLoad,
                    'pcs_online' => $selfOnline,
                    'active_sessions' => $selfActive,
                    'free_pcs' => $selfFree,
                ],
                'config' => $config,
                'passport' => [
                    'enabled' => true,
                    'members_total' => $membersTotal,
                    'cross_club_members' => $crossClubMembers,
                    'portability_label' => 'Bitta MyCafe ID bilan hamkor klublarda kirish mumkin',
                ],
                'partners' => $partners,
                'overflow' => [
                    'needed' => $overflowNeed,
                    'demand_pcs' => $overflowDemandPcs,
                    'reroute_pcs' => $reroutePcs,
                    'available_in_targets' => $availableInTargets,
                    'projected_recovered_monthly' => $projectedRecoveredMonthly,
                    'targets' => $topTargets,
                ],
                'auction' => [
                    'recommended_bid_uzs' => $auctionBid,
                    'floor_uzs' => (int)$config['auction_floor_uzs'],
                    'ceiling_uzs' => (int)$config['auction_ceiling_uzs'],
                    'reason' => $overflowNeed
                        ? 'Yuqori yuklama uchun inbound trafik bidni oshirish tavsiya etiladi'
                        : 'Normal rejim: o\'rta bid bilan networkdan trafik yig\'ish mumkin',
                ],
                'network' => [
                    'partners_total' => (int)$partners->count(),
                    'partners_ready' => (int)$partners->where('can_receive', true)->count(),
                    'partners_disabled' => $disabledCandidates,
                ],
                'pitch' => [
                    'headline' => 'MyCafe Exchange Network',
                    'subline' => 'Bitta klub emas, butun tarmoq bo\'ylab mijoz oqimi.',
                    'value' => 'Bo\'sh joy bo\'lsa trafik olasiz, to\'liq bo\'lsa trafikni yo\'naltirasiz.',
                ],
            ],
        ]);
    }

    public function exchangeConfig(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;

        $payload = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'min_free_pcs' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'referral_bonus_uzs' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'overflow_enabled' => ['nullable', 'boolean'],
            'auction_floor_uzs' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'auction_ceiling_uzs' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        $current = $this->normalizeExchangeConfig(
            (array)SettingService::get($tenantId, 'exchange_network', $this->defaultExchangeConfig())
        );
        $next = array_merge($current, $payload);

        if ((int)$next['auction_floor_uzs'] > (int)$next['auction_ceiling_uzs']) {
            $tmp = (int)$next['auction_floor_uzs'];
            $next['auction_floor_uzs'] = (int)$next['auction_ceiling_uzs'];
            $next['auction_ceiling_uzs'] = $tmp;
        }

        $next = $this->normalizeExchangeConfig($next);
        SettingService::set($tenantId, 'exchange_network', $next);

        try {
            EventLogger::log(
                $tenantId,
                'exchange_config_updated',
                'operator',
                'operator',
                (int)$operator->id,
                ['config' => $next]
            );
        } catch (\Throwable $e) {
            // no-op
        }

        return response()->json([
            'data' => [
                'saved' => true,
                'config' => $next,
            ],
        ]);
    }

    private function buildAutopilotPlan(int $tenantId, Carbon $from, Carbon $to, string $strategy): array
    {
        $report = $this->buildTenantReport($tenantId, $from, $to);

        $summary = $report['summary'] ?? [];
        $activity = $report['activity'] ?? [];
        $sales = $report['sales'] ?? [];
        $insights = $report['insights'] ?? [];
        $sessions = $report['sessions'] ?? [];
        $daily = $report['daily'] ?? [];
        $periodDays = max(1, (int)($report['period']['days'] ?? ($from->diffInDays($to) + 1)));
        $overallUtilization = (float)($summary['utilization_pct'] ?? 0);
        $pcsTotal = max(1, (int)($activity['pcs_total'] ?? 0));

        $strategyPresets = [
            'balanced' => ['max_up' => 12, 'max_down' => 10, 'promo_uplift' => 8],
            'growth' => ['max_up' => 18, 'max_down' => 12, 'promo_uplift' => 12],
            'aggressive' => ['max_up' => 25, 'max_down' => 15, 'promo_uplift' => 16],
        ];
        $preset = $strategyPresets[$strategy] ?? $strategyPresets['balanced'];

        $avgSessionMinutes = max(1, (float)($summary['avg_session_minutes'] ?? 60));

        $hourlyInsights = collect($sessions['hourly_distribution'] ?? [])
            ->map(function ($row) use ($pcsTotal, $periodDays, $avgSessionMinutes) {
                $sessionsCount = (int)($row['sessions_count'] ?? 0);
                $capacityMinutes = max(1, $pcsTotal * $periodDays * 60);
                $estimatedMinutes = $sessionsCount * $avgSessionMinutes;
                $occupancyPct = round(min(100, ($estimatedMinutes / $capacityMinutes) * 100), 1);

                return [
                    'hour' => (int)($row['hour'] ?? 0),
                    'label' => (string)($row['label'] ?? '00:00'),
                    'sessions_count' => $sessionsCount,
                    'revenue' => (int)($row['revenue'] ?? 0),
                    'occupancy_pct' => $occupancyPct,
                ];
            })
            ->values();

        $peakHours = $hourlyInsights
            ->sortByDesc('occupancy_pct')
            ->take(4)
            ->values()
            ->all();

        $lowHours = $hourlyInsights
            ->sortBy(function ($row) {
                $row = is_array($row) ? $row : [];
                return (((float)$row['occupancy_pct']) * 10000)
                    + (((int)$row['sessions_count']) * 10)
                    + ((int)$row['hour'] / 100);
            })
            ->take(4)
            ->values()
            ->all();

        $zoneUpdates = $this->buildZoneAutopilotRecommendations(
            $tenantId,
            (array)($report['zones']['items'] ?? []),
            $periodDays,
            $overallUtilization,
            $preset
        );

        $promotionPlan = $this->buildPromotionAutopilotPlan(
            $hourlyInsights,
            collect($sessions['weekday_distribution'] ?? []),
            $sales,
            $periodDays,
            $preset,
            $strategy
        );

        $zoneMonthlyUplift = (int)collect($zoneUpdates)->sum('expected_monthly_uplift');
        $promoMonthlyUplift = (int)($promotionPlan['expected_monthly_uplift'] ?? 0);

        $scenario = $this->buildAutopilotScenarios(
            (float)($insights['projection']['daily_average_net'] ?? 0),
            $zoneMonthlyUplift,
            $promoMonthlyUplift
        );

        $confidence = $this->clampInt(
            (int)round(
                45
                + min(30, count($daily))
                + min(20, ((int)($summary['sessions_count'] ?? 0)) / 25)
            ),
            45,
            95
        );
        $beastState = SettingService::get($tenantId, 'beast_mode', ['enabled' => false]);
        if (!is_array($beastState)) {
            $beastState = ['enabled' => false];
        }
        $beastMode = $this->buildBeastModeInsights(
            $report,
            $scenario,
            $promotionPlan,
            $strategy,
            (bool)($beastState['enabled'] ?? false),
            $periodDays
        );
        $beastMode['state'] = $beastState;

        return [
            'strategy' => $strategy,
            'generated_at' => now()->toDateTimeString(),
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $periodDays,
            ],
            'baseline' => [
                'net_sales' => (int)($summary['net_sales'] ?? 0),
                'gross_sales' => (int)($summary['gross_sales'] ?? 0),
                'sessions_count' => (int)($summary['sessions_count'] ?? 0),
                'utilization_pct' => $overallUtilization,
                'avg_session_minutes' => (float)($summary['avg_session_minutes'] ?? 0),
                'daily_average_net' => (float)($insights['projection']['daily_average_net'] ?? 0),
                'yearly_from_average' => (int)($insights['projection']['yearly_from_average'] ?? 0),
            ],
            'demand' => [
                'peak_hours' => $peakHours,
                'low_hours' => $lowHours,
                'heatmap' => $hourlyInsights->all(),
            ],
            'actions' => [
                'zone_price_updates' => $zoneUpdates,
                'promotion' => $promotionPlan,
            ],
            'beast_mode' => $beastMode,
            'what_if' => $scenario,
            'meta' => [
                'confidence_pct' => $confidence,
                'zones_to_update' => count($zoneUpdates),
                'promotion_enabled' => (bool)($promotionPlan['enabled'] ?? false),
            ],
        ];
    }

    private function buildBeastModeInsights(
        array $report,
        array $scenario,
        array $promotionPlan,
        string $strategy,
        bool $enabled,
        int $periodDays
    ): array {
        $summary = (array)($report['summary'] ?? []);
        $activity = (array)($report['activity'] ?? []);
        $payments = (array)($report['payments'] ?? []);
        $operators = (array)($report['operators']['items'] ?? []);
        $daily = collect($report['daily'] ?? []);

        $grossSales = (int)($summary['gross_sales'] ?? 0);
        $netSales = (int)($summary['net_sales'] ?? 0);
        $returnsTotal = (int)($summary['returns_total'] ?? 0);
        $expensesTotal = (int)($summary['expenses_total'] ?? 0);
        $returnsRatio = $grossSales > 0 ? round(($returnsTotal / $grossSales) * 100, 2) : 0.0;
        $expensesRatio = $grossSales > 0 ? round(($expensesTotal / $grossSales) * 100, 2) : 0.0;

        $pcsTotal = max(1, (int)($activity['pcs_total'] ?? 0));
        $pcsOnline = (int)($activity['pcs_online'] ?? 0);
        $offlinePct = round(max(0, (($pcsTotal - $pcsOnline) / $pcsTotal) * 100), 1);

        $operatorTurnover = collect($operators)
            ->map(function ($row) {
                $row = is_array($row) ? $row : [];
                return ((int)($row['sales_amount'] ?? 0)) + ((int)($row['sessions_revenue'] ?? 0));
            })
            ->values();
        $topOperatorShare = 0.0;
        if ($operatorTurnover->sum() > 0) {
            $topOperatorShare = round(($operatorTurnover->max() / $operatorTurnover->sum()) * 100, 1);
        }

        $riskSignals = [
            $this->buildRiskSignal(
                'returns_ratio',
                'Qaytarish ulushi',
                $returnsRatio . '%',
                6.0,
                $returnsRatio,
                max(0, (int)round($grossSales * max(0, ($returnsRatio - 6.0)) / 100 * (30 / max(1, $periodDays))))
            ),
            $this->buildRiskSignal(
                'expenses_ratio',
                'Xarajat ulushi',
                $expensesRatio . '%',
                22.0,
                $expensesRatio,
                max(0, (int)round($grossSales * max(0, ($expensesRatio - 22.0)) / 100 * (30 / max(1, $periodDays))))
            ),
            $this->buildRiskSignal(
                'offline_pc_ratio',
                'Offline PC ulushi',
                $offlinePct . '%',
                25.0,
                $offlinePct,
                max(0, (int)round($netSales * max(0, ($offlinePct - 25.0)) / 100 * 0.4 * (30 / max(1, $periodDays))))
            ),
            $this->buildRiskSignal(
                'operator_concentration',
                'Top operator ulushi',
                $topOperatorShare . '%',
                55.0,
                $topOperatorShare,
                max(0, (int)round($netSales * max(0, ($topOperatorShare - 55.0)) / 100 * 0.25 * (30 / max(1, $periodDays))))
            ),
        ];

        $riskScore = (int)round(
            collect($riskSignals)->sum(function (array $signal) {
                $weight = match ($signal['status']) {
                    'high' => 26,
                    'medium' => 14,
                    default => 4,
                };
                return $weight;
            })
        );
        $riskScore = $this->clampInt($riskScore, 10, 98);

        $leakageMonthly = (int)collect($riskSignals)->sum('impact_uzs');

        $dailyAverageNet = (float)($scenario['monthly_base'] ?? 0) / 30;
        $dailyMinNet = $daily->count() > 0
            ? (float)$daily->min('net_sales')
            : $dailyAverageNet * 0.65;
        $conservative = collect($scenario['scenarios'] ?? [])
            ->firstWhere('key', 'conservative');
        $expected = collect($scenario['scenarios'] ?? [])
            ->firstWhere('key', 'expected');

        $monthlyFloorBefore = (int)round(max(0, $dailyMinNet) * 30);
        $monthlyFloorAfter = (int)round(
            $monthlyFloorBefore
            + ((int)($conservative['uplift_monthly'] ?? 0))
            + ($leakageMonthly * 0.22)
        );
        $yearlyFloorAfter = $monthlyFloorAfter * 12;

        $sleepFrom = (string)($promotionPlan['time_from'] ?? '02:00');
        $sleepTo = (string)($promotionPlan['time_to'] ?? '07:00');
        $cashSales = (int)($payments['cash_sales_total'] ?? 0);
        $energyMonthlySavings = (int)round(
            max(1, $pcsTotal)
            * 16000
            * (1 + max(0, ($offlinePct - 10)) / 100)
        );

        $guaranteedUpliftPct = $monthlyFloorBefore > 0
            ? round((($monthlyFloorAfter - $monthlyFloorBefore) / $monthlyFloorBefore) * 100, 1)
            : 0.0;

        return [
            'enabled' => $enabled,
            'strategy' => $strategy,
            'headline' => 'Profit Guarantee Engine',
            'subline' => 'Biz dastur emas, oylik sof foyda natijasini boshqaramiz.',
            'profit_guard' => [
                'window_days' => 60,
                'monthly_floor_before' => $monthlyFloorBefore,
                'monthly_floor_after' => $monthlyFloorAfter,
                'yearly_floor_after' => $yearlyFloorAfter,
                'expected_monthly_net' => (int)($expected['monthly_net'] ?? 0),
                'expected_monthly_uplift' => (int)($expected['uplift_monthly'] ?? 0),
                'guaranteed_uplift_pct' => $guaranteedUpliftPct,
            ],
            'leak_watch' => [
                'risk_score' => $riskScore,
                'estimated_monthly_leakage' => $leakageMonthly,
                'signals' => $riskSignals,
            ],
            'energy_optimizer' => [
                'recommended_sleep_window' => [
                    'from' => $sleepFrom,
                    'to' => $sleepTo,
                ],
                'policy' => [
                    'auto_sleep_after_min' => 25,
                    'auto_wake_before_peak_min' => 15,
                    'exclude_if_active_sessions' => true,
                ],
                'estimated_monthly_savings' => $energyMonthlySavings,
                'cashflow_buffer_impact' => (int)round(min($cashSales, $energyMonthlySavings * 0.35)),
            ],
            'pitch' => [
                'owner' => '60 kun ichida foyda trayektoriyasini ko\'taradigan AI nazorat rejimi.',
                'operator' => 'Yuqori yo\'qotish signalini oldindan ko\'rsatib, xatolarni kamaytiradi.',
                'sales' => 'Feature emas, kafolatlangan natija: Profit Guard + Leak Watch + Energy Optimizer.',
            ],
        ];
    }

    private function buildRiskSignal(
        string $key,
        string $label,
        string $valueLabel,
        float $threshold,
        float $actual,
        int $impactUzs
    ): array {
        $status = 'ok';
        if ($actual >= $threshold * 1.35) {
            $status = 'high';
        } elseif ($actual >= $threshold) {
            $status = 'medium';
        }

        return [
            'key' => $key,
            'label' => $label,
            'value' => $valueLabel,
            'threshold' => $threshold,
            'actual' => round($actual, 2),
            'status' => $status,
            'impact_uzs' => max(0, $impactUzs),
        ];
    }

    private function buildZoneAutopilotRecommendations(
        int $tenantId,
        array $zoneMetrics,
        int $periodDays,
        float $overallUtilization,
        array $preset
    ): array {
        $zoneNameExpr = "COALESCE(NULLIF(TRIM(COALESCE(zone,'')), ''), 'No zone')";

        $zonePcCounts = Pc::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            })
            ->selectRaw("{$zoneNameExpr} as zone_name, COUNT(*) as pcs_count")
            ->groupByRaw($zoneNameExpr)
            ->pluck('pcs_count', 'zone_name');

        $zoneMetricsMap = collect($zoneMetrics)->keyBy(function ($row) {
            $row = is_array($row) ? $row : [];
            $name = trim((string)($row['zone'] ?? ''));
            return $name !== '' ? $name : 'No zone';
        });

        $rows = Zone::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price_per_hour']);

        $updates = [];
        foreach ($rows as $zone) {
            $zoneName = trim((string)$zone->name);
            $zoneName = $zoneName !== '' ? $zoneName : 'No zone';
            $currentPrice = max(0, (int)$zone->price_per_hour);
            if ($currentPrice <= 0) {
                continue;
            }

            $metric = $zoneMetricsMap->get($zoneName, []);
            $pcCount = max(0, (int)($zonePcCounts[$zoneName] ?? 0));
            $zoneMinutes = (int)($metric['minutes'] ?? 0);
            $zoneRevenue = (int)($metric['revenue'] ?? 0);
            $zoneUtilization = $pcCount > 0
                ? round(min(100, ($zoneMinutes / max(1, ($pcCount * $periodDays * 24 * 60))) * 100), 1)
                : $overallUtilization;

            $deltaPct = $this->resolvePriceDeltaPct($zoneUtilization, $overallUtilization, $preset);
            if ($deltaPct === 0) {
                continue;
            }

            $recommended = $this->roundPrice((int)round($currentPrice * (1 + ($deltaPct / 100))));
            if ($recommended <= 0 || $recommended === $currentPrice) {
                continue;
            }

            $monthlyFactor = $zoneUtilization >= 60 ? 0.55 : 0.25;
            $expectedMonthlyUplift = (int)round(
                $zoneRevenue
                * ($deltaPct / 100)
                * $monthlyFactor
                * (30 / max(1, $periodDays))
            );

            $updates[] = [
                'zone_id' => (int)$zone->id,
                'zone_name' => $zoneName,
                'pc_count' => $pcCount,
                'current_price_per_hour' => $currentPrice,
                'recommended_price_per_hour' => $recommended,
                'delta_pct' => $deltaPct,
                'utilization_pct' => $zoneUtilization,
                'expected_monthly_uplift' => $expectedMonthlyUplift,
                'reason' => $deltaPct > 0
                    ? 'High load zone: higher tariff during current demand profile'
                    : 'Low load zone: softer tariff to increase occupancy',
            ];
        }

        return collect($updates)
            ->sortByDesc(function ($row) {
                $row = is_array($row) ? $row : [];
                return abs((float)($row['delta_pct'] ?? 0));
            })
            ->values()
            ->all();
    }

    private function buildPromotionAutopilotPlan(
        $hourlyInsights,
        $weekdayDistribution,
        array $sales,
        int $periodDays,
        array $preset,
        string $strategy
    ): array {
        $window = $this->resolveLowTrafficWindow($hourlyInsights);

        $topupTotal = (int)($sales['topup_total'] ?? 0);
        $topupBonusTotal = (int)($sales['topup_bonus_total'] ?? 0);
        $bonusRatio = $topupTotal > 0 ? round(($topupBonusTotal / $topupTotal) * 100, 2) : 0.0;

        $lowHoursAvg = (float)($window['avg_occupancy_pct'] ?? 0);
        $enabled = $lowHoursAvg <= 38 || $bonusRatio < 1.5;

        $daysOfWeek = $weekdayDistribution
            ->sortBy(function ($row) {
                return (((int)($row['sessions_count'] ?? 0)) * 10)
                    + ((int)($row['weekday_no'] ?? 0) / 100);
            })
            ->take(4)
            ->map(fn($row) => (int)($row['weekday_no'] ?? 0))
            ->values()
            ->all();
        $daysOfWeek = count($daysOfWeek) >= 7 ? null : $daysOfWeek;

        $expectedTopupUpliftPct = max(4, (int)($preset['promo_uplift'] ?? 8));
        $expectedMonthlyUplift = (int)round(
            $topupTotal
            * ($expectedTopupUpliftPct / 100)
            * 0.35
            * (30 / max(1, $periodDays))
        );

        return [
            'enabled' => $enabled,
            'name' => 'AI Booster ' . $window['time_from'] . '-' . $window['time_to'],
            'type' => 'double_topup',
            'applies_payment_method' => 'cash',
            'days_of_week' => $daysOfWeek,
            'time_from' => $window['time_from'],
            'time_to' => $window['time_to'],
            'starts_at' => now()->startOfDay()->toDateTimeString(),
            'ends_at' => now()->addDays(21)->endOfDay()->toDateTimeString(),
            'priority' => $strategy === 'aggressive' ? 3 : ($strategy === 'growth' ? 5 : 8),
            'expected_topup_uplift_pct' => $enabled ? $expectedTopupUpliftPct : 0,
            'expected_monthly_uplift' => $enabled ? $expectedMonthlyUplift : 0,
            'reason' => $enabled
                ? 'Low-demand window detected: auto topup bonus can lift traffic and topups'
                : 'Current bonus performance is healthy; promotion can remain unchanged',
        ];
    }

    private function buildAutopilotScenarios(float $dailyAverageNet, int $zoneMonthlyUplift, int $promoMonthlyUplift): array
    {
        $baseMonthly = (int)round($dailyAverageNet * 30);
        $combinedMonthly = $zoneMonthlyUplift + $promoMonthlyUplift;
        $conservativeMonthly = (int)round($combinedMonthly * 0.45);
        $expectedMonthly = (int)round($combinedMonthly * 0.8);
        $optimisticMonthly = (int)round($combinedMonthly * 1.2);

        return [
            'monthly_base' => $baseMonthly,
            'yearly_base' => $baseMonthly * 12,
            'monthly_uplift_estimate' => $combinedMonthly,
            'yearly_uplift_estimate' => $combinedMonthly * 12,
            'zone_monthly_uplift_estimate' => $zoneMonthlyUplift,
            'promotion_monthly_uplift_estimate' => $promoMonthlyUplift,
            'scenarios' => [
                [
                    'key' => 'conservative',
                    'label' => 'Conservative',
                    'monthly_net' => $baseMonthly + $conservativeMonthly,
                    'yearly_net' => ($baseMonthly + $conservativeMonthly) * 12,
                    'uplift_monthly' => $conservativeMonthly,
                ],
                [
                    'key' => 'expected',
                    'label' => 'Expected',
                    'monthly_net' => $baseMonthly + $expectedMonthly,
                    'yearly_net' => ($baseMonthly + $expectedMonthly) * 12,
                    'uplift_monthly' => $expectedMonthly,
                ],
                [
                    'key' => 'optimistic',
                    'label' => 'Optimistic',
                    'monthly_net' => $baseMonthly + $optimisticMonthly,
                    'yearly_net' => ($baseMonthly + $optimisticMonthly) * 12,
                    'uplift_monthly' => $optimisticMonthly,
                ],
            ],
        ];
    }

    private function resolveLowTrafficWindow($hourlyInsights): array
    {
        $hourMap = collect($hourlyInsights)->keyBy(fn($row) => (int)($row['hour'] ?? 0));
        $lowest = collect($hourlyInsights)
            ->sortBy(function ($row) {
                return (((float)($row['occupancy_pct'] ?? 0)) * 10000)
                    + (((int)($row['sessions_count'] ?? 0)) * 10)
                    + ((int)($row['hour'] ?? 0) / 100);
            })
            ->first();
        $startHour = (int)($lowest['hour'] ?? 10);

        $duration = 5;
        $hours = [];
        for ($i = 0; $i < $duration; $i++) {
            $hours[] = ($startHour + $i) % 24;
        }
        $endHour = ($startHour + $duration) % 24;

        $avgOccupancy = collect($hours)
            ->map(function (int $hour) use ($hourMap) {
                return (float)($hourMap->get($hour)['occupancy_pct'] ?? 0);
            })
            ->avg();

        return [
            'hours' => $hours,
            'time_from' => str_pad((string)$startHour, 2, '0', STR_PAD_LEFT) . ':00',
            'time_to' => str_pad((string)$endHour, 2, '0', STR_PAD_LEFT) . ':00',
            'avg_occupancy_pct' => round((float)$avgOccupancy, 1),
        ];
    }

    private function resolvePriceDeltaPct(float $zoneUtilization, float $overallUtilization, array $preset): int
    {
        $maxUp = (int)($preset['max_up'] ?? 12);
        $maxDown = (int)($preset['max_down'] ?? 10);
        $delta = 0;

        if ($zoneUtilization >= 85) {
            $delta = $maxUp;
        } elseif ($zoneUtilization >= 70) {
            $delta = (int)round($maxUp * 0.65);
        } elseif ($zoneUtilization <= 20) {
            $delta = -$maxDown;
        } elseif ($zoneUtilization <= 35) {
            $delta = -(int)round($maxDown * 0.6);
        }

        if ($overallUtilization > 75 && $delta > 0) {
            $delta += 2;
        } elseif ($overallUtilization < 35 && $delta < 0) {
            $delta -= 2;
        }

        return $this->clampInt($delta, -$maxDown, $maxUp);
    }

    private function roundPrice(int $value): int
    {
        if ($value <= 0) {
            return 0;
        }

        $rounded = (int)(round($value / 500) * 500);
        return $this->clampInt($rounded, 5000, 500000);
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function defaultExchangeConfig(): array
    {
        return [
            'enabled' => false,
            'radius_km' => 20,
            'min_free_pcs' => 2,
            'referral_bonus_uzs' => 12000,
            'overflow_enabled' => true,
            'auction_floor_uzs' => 6000,
            'auction_ceiling_uzs' => 26000,
        ];
    }

    private function normalizeExchangeConfig(array $config): array
    {
        $defaults = $this->defaultExchangeConfig();
        $cfg = array_merge($defaults, $config);

        $floor = $this->clampInt((int)($cfg['auction_floor_uzs'] ?? $defaults['auction_floor_uzs']), 0, 10000000);
        $ceil = $this->clampInt((int)($cfg['auction_ceiling_uzs'] ?? $defaults['auction_ceiling_uzs']), 0, 10000000);
        if ($floor > $ceil) {
            [$floor, $ceil] = [$ceil, $floor];
        }

        return [
            'enabled' => (bool)($cfg['enabled'] ?? $defaults['enabled']),
            'radius_km' => (int)$this->clampInt((int)($cfg['radius_km'] ?? $defaults['radius_km']), 1, 300),
            'min_free_pcs' => (int)$this->clampInt((int)($cfg['min_free_pcs'] ?? $defaults['min_free_pcs']), 0, 1000),
            'referral_bonus_uzs' => (int)$this->clampInt((int)($cfg['referral_bonus_uzs'] ?? $defaults['referral_bonus_uzs']), 0, 10000000),
            'overflow_enabled' => (bool)($cfg['overflow_enabled'] ?? $defaults['overflow_enabled']),
            'auction_floor_uzs' => $floor,
            'auction_ceiling_uzs' => $ceil,
        ];
    }

    private function parseClubLocation($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $lat = $raw['lat'] ?? null;
        $lng = $raw['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        return [
            'lat' => (float)$lat,
            'lng' => (float)$lng,
            'address' => isset($raw['address']) ? (string)$raw['address'] : null,
        ];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
        return $earth * $c;
    }

    private function resolveRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $to = isset($data['to'])
            ? Carbon::parse($data['to'])->endOfDay()
            : now()->endOfDay();

        $from = isset($data['from'])
            ? Carbon::parse($data['from'])->startOfDay()
            : $to->copy()->subDays(6)->startOfDay();

        $days = $from->diffInDays($to) + 1;
        if ($days > 120) {
            throw ValidationException::withMessages([
                'from' => 'Date range must be 120 days or less',
            ]);
        }

        return [$from, $to];
    }

    private function buildTenantReport(int $tenantId, Carbon $from, Carbon $to): array
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

        $txMetrics = (clone $txBase)->selectRaw("
            COALESCE(SUM(CASE WHEN type='topup' THEN amount ELSE 0 END),0) AS topup_total,
            COALESCE(SUM(CASE WHEN type='package' THEN amount ELSE 0 END),0) AS package_total,
            COALESCE(SUM(CASE WHEN type='subscription' THEN amount ELSE 0 END),0) AS subscription_total,
            COALESCE(SUM(CASE WHEN type='transfer_in' THEN amount ELSE 0 END),0) AS transfer_in_total,
            COALESCE(SUM(CASE WHEN type='transfer_out' THEN ABS(amount) ELSE 0 END),0) AS transfer_out_total,
            COALESCE(SUM(CASE WHEN type='tier_upgrade_bonus' THEN bonus_amount ELSE 0 END),0) AS tier_bonus_total,
            COALESCE(SUM(CASE WHEN type='topup' THEN bonus_amount ELSE 0 END),0) AS topup_bonus_total,

            COALESCE(SUM(CASE WHEN payment_method='cash' AND type IN ('topup','package','subscription') THEN amount ELSE 0 END),0) AS cash_sales_total,
            COALESCE(SUM(CASE WHEN payment_method='card' AND type IN ('topup','package','subscription') THEN amount ELSE 0 END),0) AS card_sales_total,
            COALESCE(SUM(CASE WHEN payment_method='balance' AND type IN ('topup','package','subscription') THEN amount ELSE 0 END),0) AS balance_sales_total,

            COUNT(*) AS tx_count,
            SUM(CASE WHEN type='topup' THEN 1 ELSE 0 END) AS topup_count,
            SUM(CASE WHEN type='package' THEN 1 ELSE 0 END) AS package_count,
            SUM(CASE WHEN type='subscription' THEN 1 ELSE 0 END) AS subscription_count
        ")->first();

        $sessionMetrics = (clone $sessionBase)->selectRaw("
            COUNT(*) AS sessions_count,
            COALESCE(SUM(price_total),0) AS sessions_revenue,
            COALESCE(AVG(EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at))/60),0) AS avg_session_minutes,
            COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at))/60),0) AS total_session_minutes
        ")->first();

        $returnMetrics = (clone $returnBase)->selectRaw("
            COUNT(*) AS returns_count,
            COALESCE(SUM(amount),0) AS returns_total,
            COALESCE(SUM(CASE WHEN payment_method='cash' THEN amount ELSE 0 END),0) AS returns_cash_total,
            COALESCE(SUM(CASE WHEN payment_method='card' THEN amount ELSE 0 END),0) AS returns_card_total,
            COALESCE(SUM(CASE WHEN payment_method='balance' THEN amount ELSE 0 END),0) AS returns_balance_total
        ")->first();

        $expensesTotal = (int)(clone $expenseBase)->sum('amount');

        $pcBase = Pc::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            });

        $pcsTotal = (int)(clone $pcBase)->count();
        $pcsOnline = (int)(clone $pcBase)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subMinutes(3))
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

    private function buildKpiSnapshot(int $tenantId, Carbon $from, Carbon $to): array
    {
        $tx = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type IN ('topup','package','subscription') THEN amount ELSE 0 END),0) AS gross_sales,
                COALESCE(SUM(CASE WHEN type='topup' AND payment_method='cash' THEN amount ELSE 0 END),0) AS cash_topup,
                COALESCE(SUM(CASE WHEN type='package' AND payment_method='cash' THEN amount ELSE 0 END),0) AS cash_package,
                COALESCE(SUM(CASE WHEN type='subscription' AND payment_method='cash' THEN amount ELSE 0 END),0) AS cash_subscription,
                COALESCE(SUM(CASE WHEN type='topup' AND payment_method='card' THEN amount ELSE 0 END),0) AS card_topup,
                COALESCE(SUM(CASE WHEN type='package' AND payment_method='card' THEN amount ELSE 0 END),0) AS card_package,
                COALESCE(SUM(CASE WHEN type='subscription' AND payment_method='card' THEN amount ELSE 0 END),0) AS card_subscription
            ")
            ->first();
        $returns = ReturnRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                COALESCE(SUM(amount),0) AS returns_total,
                COALESCE(SUM(CASE WHEN payment_method='cash' THEN amount ELSE 0 END),0) AS returns_cash_total,
                COALESCE(SUM(CASE WHEN payment_method='card' THEN amount ELSE 0 END),0) AS returns_card_total
            ")
            ->first();
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

    private function compareKpis(array $leftReport, array $rightReport): array
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
}
