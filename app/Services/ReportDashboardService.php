<?php

namespace App\Services;

use App\Models\Pc;
use App\Models\Tenant;
use Carbon\Carbon;

class ReportDashboardService
{
    private const INSIGHT_TYPE_WEIGHT = [
        'risk' => 0,
        'opportunity' => 1,
        'positive' => 2,
        'info' => 3,
    ];

    public function __construct(
        private readonly TenantReportService $reports,
    ) {}

    public function buildOverview(int $tenantId, Carbon $from, Carbon $to): array
    {
        return [
            'tenant' => Tenant::query()->find($tenantId, ['id', 'name', 'status']),
            'range' => $this->formatRange($from, $to),
            'report' => $this->reports->build($tenantId, $from, $to),
        ];
    }

    public function buildAiInsights(int $tenantId, Carbon $from, Carbon $to): array
    {
        $report = $this->reports->build($tenantId, $from, $to);
        $metrics = $this->extractMetrics($report);
        [$zonePcMap, $totalPcs] = $this->buildZonePcInventory($tenantId);

        $insights = [];
        $this->addUtilizationInsights($insights, $metrics);
        $this->addGrowthInsights($insights, $metrics);
        $this->addCostAndQualityInsights($insights, $metrics);
        $this->addCommercialInsights($insights, $metrics);
        $this->addSessionPatternInsights($insights, $report, $metrics);
        $this->addZoneInsights($insights, (array) ($report['zones'] ?? []), $zonePcMap, $totalPcs);
        $this->addOperatorInsights($insights, (array) ($report['operators'] ?? []));
        $this->sortInsights($insights);

        return [
            'range' => $this->formatRange($from, $to),
            'kpis' => $this->buildKpisPayload($metrics),
            'insights' => $insights,
        ];
    }

    private function extractMetrics(array $report): array
    {
        $summary = (array) ($report['summary'] ?? []);
        $payments = (array) ($report['payments'] ?? []);
        $activity = (array) ($report['activity'] ?? []);
        $sales = (array) ($report['sales'] ?? []);
        $clients = (array) ($report['clients'] ?? []);
        $sessions = (array) ($report['sessions'] ?? []);
        $pcs = (array) ($report['pcs'] ?? []);
        $shifts = (array) ($report['shifts'] ?? []);
        $growth = (array) ($report['growth'] ?? []);

        $grossSales = (float) ($summary['gross_sales'] ?? 0);
        $cashSales = (float) ($payments['cash_sales_total'] ?? 0);
        $cardSales = (float) ($payments['card_sales_total'] ?? 0);
        $balanceSales = (float) ($payments['balance_sales_total'] ?? 0);
        $paymentsTotal = $cashSales + $cardSales + $balanceSales;
        $topupTotal = (float) ($sales['topup_total'] ?? 0);
        $packageTotal = (float) ($sales['package_total'] ?? 0);
        $subscriptionTotal = (float) ($sales['subscription_total'] ?? 0);
        $clientsInPeriod = (int) ($activity['clients_in_period'] ?? 0);
        $newClients = (int) ($clients['new_clients_in_period'] ?? 0);
        $returningClients = (int) ($clients['returning_clients'] ?? 0);
        $activeSessionsNow = (int) ($activity['active_sessions_now'] ?? 0);
        $pcsOnline = (int) ($activity['pcs_online'] ?? 0);
        $returnsCount = (int) ($summary['returns_count'] ?? 0);
        $txCount = (int) ($summary['tx_count'] ?? 0);
        $bonusTotal = (float) ($sales['topup_bonus_total'] ?? 0) + (float) ($sales['tier_bonus_total'] ?? 0);

        return [
            'gross_sales' => $grossSales,
            'net_sales' => (float) ($summary['net_sales'] ?? 0),
            'sessions_count' => (int) ($summary['sessions_count'] ?? 0),
            'utilization_pct' => (float) ($summary['utilization_pct'] ?? 0),
            'avg_session_minutes' => (float) ($summary['avg_session_minutes'] ?? 0),
            'expenses_total' => (float) ($summary['expenses_total'] ?? 0),
            'returns_total' => (float) ($summary['returns_total'] ?? 0),
            'returns_ratio_pct' => $grossSales > 0 ? round((((float) ($summary['returns_total'] ?? 0)) / $grossSales) * 100, 1) : 0.0,
            'expenses_ratio_pct' => $grossSales > 0 ? round((((float) ($summary['expenses_total'] ?? 0)) / $grossSales) * 100, 1) : 0.0,
            'cash_sales_total' => $cashSales,
            'card_sales_total' => $cardSales,
            'balance_sales_total' => $balanceSales,
            'card_share_pct' => $paymentsTotal > 0 ? round(($cardSales / $paymentsTotal) * 100, 1) : 0.0,
            'topup_total' => $topupTotal,
            'package_total' => $packageTotal,
            'subscription_total' => $subscriptionTotal,
            'package_share_pct' => $grossSales > 0 ? round((($packageTotal + $subscriptionTotal) / $grossSales) * 100, 1) : 0.0,
            'clients_in_period' => $clientsInPeriod,
            'new_clients_ratio_pct' => $clientsInPeriod > 0 ? round(($newClients / $clientsInPeriod) * 100, 1) : 0.0,
            'returning_clients_ratio_pct' => $clientsInPeriod > 0 ? round(($returningClients / $clientsInPeriod) * 100, 1) : 0.0,
            'avg_sessions_per_client' => (float) ($sessions['avg_sessions_per_client'] ?? 0),
            'avg_session_revenue' => (float) ($sessions['avg_session_revenue'] ?? 0),
            'avg_topup_check' => (float) ($clients['avg_topup_check'] ?? 0),
            'active_sessions_now' => $activeSessionsNow,
            'pcs_online' => $pcsOnline,
            'live_occupancy_pct' => $pcsOnline > 0 ? round(($activeSessionsNow / $pcsOnline) * 100, 1) : 0.0,
            'net_sales_diff_pct' => $growth['net_sales']['diff_pct'] ?? null,
            'sessions_diff_pct' => $growth['sessions_count']['diff_pct'] ?? null,
            'returns_count' => $returnsCount,
            'returns_rate_pct' => $txCount > 0 ? round(($returnsCount / $txCount) * 100, 1) : 0.0,
            'underused_pcs_count' => count(is_array($pcs['underused'] ?? null) ? $pcs['underused'] : []),
            'shortage_total' => (float) ($shifts['diff_shortage_total'] ?? 0),
            'peak_hour' => $sessions['peak_hour']['label'] ?? null,
            'bonus_ratio_pct' => $grossSales > 0 ? round(($bonusTotal / $grossSales) * 100, 1) : 0.0,
            'bonus_total' => $bonusTotal,
        ];
    }

    private function addUtilizationInsights(array &$insights, array $metrics): void
    {
        $utilization = (float) ($metrics['utilization_pct'] ?? 0);

        if ($utilization <= 30) {
            $this->pushInsight(
                $insights,
                'low_utilization',
                'risk',
                1,
                ['utilization_pct' => round($utilization, 1)],
                ['off_peak_promo', 'price_tune', 'marketing_push'],
                'high',
                $utilization <= 20 ? 85 : 70,
                [$this->evidence('utilization', round($utilization, 1), '%')],
            );
        } elseif ($utilization <= 50) {
            $this->pushInsight(
                $insights,
                'medium_utilization',
                'opportunity',
                3,
                ['utilization_pct' => round($utilization, 1)],
                ['off_peak_promo', 'bundle_push'],
                'medium',
                60,
                [$this->evidence('utilization', round($utilization, 1), '%')],
            );
        } elseif ($utilization >= 85) {
            $this->pushInsight(
                $insights,
                'high_utilization',
                'opportunity',
                3,
                ['utilization_pct' => round($utilization, 1)],
                ['upgrade_capacity', 'price_tune'],
                'medium',
                70,
                [$this->evidence('utilization', round($utilization, 1), '%')],
            );
        }
    }

    private function addGrowthInsights(array &$insights, array $metrics): void
    {
        $netSalesDiffPct = $metrics['net_sales_diff_pct'] ?? null;
        $sessionsDiffPct = $metrics['sessions_diff_pct'] ?? null;

        if (is_numeric($netSalesDiffPct) && (float) $netSalesDiffPct <= -10) {
            $this->pushInsight(
                $insights,
                'net_sales_drop',
                'risk',
                1,
                ['net_sales_diff_pct' => round((float) $netSalesDiffPct, 1)],
                ['off_peak_promo', 'marketing_push', 'price_tune'],
                'high',
                75,
                [
                    $this->evidence('net_sales', (int) ($metrics['net_sales'] ?? 0), 'UZS'),
                    $this->evidence('growth', round((float) $netSalesDiffPct, 1), '%'),
                ],
            );
        } elseif (is_numeric($netSalesDiffPct) && (float) $netSalesDiffPct >= 12) {
            $this->pushInsight(
                $insights,
                'net_sales_growth',
                'positive',
                5,
                ['net_sales_diff_pct' => round((float) $netSalesDiffPct, 1)],
                ['upgrade_capacity'],
                'medium',
                70,
                [
                    $this->evidence('net_sales', (int) ($metrics['net_sales'] ?? 0), 'UZS'),
                    $this->evidence('growth', round((float) $netSalesDiffPct, 1), '%'),
                ],
            );
        }

        if (is_numeric($sessionsDiffPct) && (float) $sessionsDiffPct <= -10) {
            $this->pushInsight(
                $insights,
                'sessions_drop',
                'risk',
                2,
                ['sessions_diff_pct' => round((float) $sessionsDiffPct, 1)],
                ['marketing_push', 'loyalty_push'],
                'high',
                70,
                [
                    $this->evidence('sessions', (int) ($metrics['sessions_count'] ?? 0), null),
                    $this->evidence('growth', round((float) $sessionsDiffPct, 1), '%'),
                ],
            );
        }
    }

    private function addCostAndQualityInsights(array &$insights, array $metrics): void
    {
        if ((float) ($metrics['returns_ratio_pct'] ?? 0) >= 5) {
            $this->pushInsight(
                $insights,
                'returns_high',
                'risk',
                2,
                ['returns_ratio_pct' => $metrics['returns_ratio_pct']],
                ['quality_check'],
                'high',
                70,
                [
                    $this->evidence('returns', (int) ($metrics['returns_total'] ?? 0), 'UZS'),
                    $this->evidence('returns_ratio', $metrics['returns_ratio_pct'], '%'),
                ],
            );
        }

        if ((float) ($metrics['expenses_ratio_pct'] ?? 0) >= 25) {
            $this->pushInsight(
                $insights,
                'expenses_high',
                'risk',
                2,
                ['expenses_ratio_pct' => $metrics['expenses_ratio_pct']],
                ['cost_control'],
                'high',
                70,
                [
                    $this->evidence('expenses', (int) ($metrics['expenses_total'] ?? 0), 'UZS'),
                    $this->evidence('expenses_ratio', $metrics['expenses_ratio_pct'], '%'),
                ],
            );
        }

        if ((float) ($metrics['returns_rate_pct'] ?? 0) >= 6) {
            $this->pushInsight(
                $insights,
                'returns_count_high',
                'risk',
                2,
                ['returns_rate_pct' => $metrics['returns_rate_pct']],
                ['quality_check', 'staff_training'],
                'high',
                70,
                [
                    $this->evidence('returns_count', (int) ($metrics['returns_count'] ?? 0), null),
                    $this->evidence('returns_rate', $metrics['returns_rate_pct'], '%'),
                ],
            );
        }

        if ((float) ($metrics['shortage_total'] ?? 0) > 0) {
            $this->pushInsight(
                $insights,
                'cash_shortage',
                'risk',
                2,
                ['shortage_total' => round((float) $metrics['shortage_total'], 1)],
                ['quality_check', 'cost_control'],
                'high',
                80,
                [$this->evidence('shortage', round((float) $metrics['shortage_total'], 1), 'UZS')],
            );
        }
    }

    private function addCommercialInsights(array &$insights, array $metrics): void
    {
        if ((float) ($metrics['avg_session_minutes'] ?? 0) > 0 && (float) ($metrics['avg_session_minutes'] ?? 0) < 45) {
            $this->pushInsight(
                $insights,
                'short_sessions',
                'opportunity',
                3,
                ['avg_session_minutes' => round((float) $metrics['avg_session_minutes'], 1)],
                ['bundle_push', 'loyalty_push'],
                'medium',
                60,
                [$this->evidence('avg_minutes', round((float) $metrics['avg_session_minutes'], 1), 'min')],
            );
        }

        if ((float) ($metrics['gross_sales'] ?? 0) > 0 && (float) ($metrics['package_share_pct'] ?? 0) < 15) {
            $this->pushInsight(
                $insights,
                'low_package_share',
                'opportunity',
                3,
                ['package_share_pct' => $metrics['package_share_pct']],
                ['bundle_push'],
                'medium',
                60,
                [$this->evidence('package_share', $metrics['package_share_pct'], '%')],
            );
        }

        if ((int) ($metrics['clients_in_period'] ?? 0) >= 20 && (float) ($metrics['new_clients_ratio_pct'] ?? 0) < 20) {
            $this->pushInsight(
                $insights,
                'low_new_clients',
                'opportunity',
                3,
                ['new_clients_ratio_pct' => $metrics['new_clients_ratio_pct']],
                ['marketing_push', 'loyalty_push'],
                'medium',
                60,
                [$this->evidence('new_clients_ratio', $metrics['new_clients_ratio_pct'], '%')],
            );
        }

        if (
            (int) ($metrics['clients_in_period'] ?? 0) >= 20
            && (
                (float) ($metrics['returning_clients_ratio_pct'] ?? 0) < 35
                || (float) ($metrics['avg_sessions_per_client'] ?? 0) < 1.3
            )
        ) {
            $this->pushInsight(
                $insights,
                'low_repeat_rate',
                'opportunity',
                3,
                [
                    'returning_clients_ratio_pct' => $metrics['returning_clients_ratio_pct'],
                    'avg_sessions_per_client' => round((float) ($metrics['avg_sessions_per_client'] ?? 0), 2),
                ],
                ['loyalty_push', 'bundle_push', 'welcome_back_offer'],
                'medium',
                65,
                [
                    $this->evidence('returning_clients_ratio', $metrics['returning_clients_ratio_pct'], '%'),
                    $this->evidence('avg_sessions_per_client', round((float) ($metrics['avg_sessions_per_client'] ?? 0), 2), null),
                ],
            );
        }

        if ((float) (($metrics['cash_sales_total'] ?? 0) + ($metrics['card_sales_total'] ?? 0) + ($metrics['balance_sales_total'] ?? 0)) > 0
            && (float) ($metrics['card_share_pct'] ?? 0) < 15
        ) {
            $this->pushInsight(
                $insights,
                'cash_dominant',
                'opportunity',
                4,
                ['card_share_pct' => $metrics['card_share_pct']],
                ['card_bonus'],
                'low',
                55,
                [$this->evidence('card_share', $metrics['card_share_pct'], '%')],
            );
        }

        if ((float) ($metrics['bonus_ratio_pct'] ?? 0) >= 15) {
            $this->pushInsight(
                $insights,
                'bonus_high',
                'opportunity',
                4,
                ['bonus_ratio_pct' => $metrics['bonus_ratio_pct']],
                ['cost_control', 'loyalty_push'],
                'medium',
                60,
                [
                    $this->evidence('bonus', (int) ($metrics['bonus_total'] ?? 0), 'UZS'),
                    $this->evidence('bonus_ratio', $metrics['bonus_ratio_pct'], '%'),
                ],
            );
        }

        if (
            (float) ($metrics['avg_session_revenue'] ?? 0) > 0
            && (float) ($metrics['avg_topup_check'] ?? 0) > 0
            && (float) ($metrics['avg_session_revenue'] ?? 0) < ((float) ($metrics['avg_topup_check'] ?? 0) * 0.5)
        ) {
            $this->pushInsight(
                $insights,
                'low_session_value',
                'opportunity',
                4,
                ['avg_session_revenue' => round((float) ($metrics['avg_session_revenue'] ?? 0), 1)],
                ['bundle_push', 'price_tune'],
                'low',
                55,
                [
                    $this->evidence('avg_session_revenue', round((float) ($metrics['avg_session_revenue'] ?? 0), 1), 'UZS'),
                    $this->evidence('avg_topup_check', round((float) ($metrics['avg_topup_check'] ?? 0), 1), 'UZS'),
                ],
            );
        }
    }

    private function addSessionPatternInsights(array &$insights, array $report, array $metrics): void
    {
        if ((int) ($metrics['underused_pcs_count'] ?? 0) >= 5) {
            $this->pushInsight(
                $insights,
                'underused_pcs',
                'opportunity',
                4,
                ['underused_pcs_count' => (int) ($metrics['underused_pcs_count'] ?? 0)],
                ['optimize_layout', 'price_tune'],
                'medium',
                60,
                [$this->evidence('underused_pcs', (int) ($metrics['underused_pcs_count'] ?? 0), null)],
            );
        }

        if (!empty($metrics['peak_hour'])) {
            $this->pushInsight(
                $insights,
                'peak_time',
                'info',
                5,
                ['peak_hour' => $metrics['peak_hour']],
                ['staff_peak'],
                'low',
                50,
                [$this->evidence('peak_hour', (string) $metrics['peak_hour'], null)],
            );
        }

        $sessions = (array) ($report['sessions'] ?? []);
        $hourly = is_array($sessions['hourly_distribution'] ?? null) ? $sessions['hourly_distribution'] : [];
        if (!empty($hourly)) {
            $totalHourSessions = 0;
            $peakSessions = 0;
            foreach ($hourly as $row) {
                $count = (int) ($row['sessions_count'] ?? 0);
                $totalHourSessions += $count;
                $peakSessions = max($peakSessions, $count);
            }

            $avgPerHour = count($hourly) > 0 ? $totalHourSessions / count($hourly) : 0;
            if ($avgPerHour > 0 && $peakSessions >= $avgPerHour * 2.5) {
                $this->pushInsight(
                    $insights,
                    'peak_concentration',
                    'opportunity',
                    3,
                    ['peak_ratio' => round($peakSessions / $avgPerHour, 2)],
                    ['off_peak_promo', 'price_tune'],
                    'medium',
                    65,
                    [
                        $this->evidence('peak_hour', (string) ($metrics['peak_hour'] ?? '-'), null),
                        $this->evidence('sessions', $peakSessions, null),
                    ],
                );
            }
        }

        $weekday = is_array($sessions['weekday_distribution'] ?? null) ? $sessions['weekday_distribution'] : [];
        if (!empty($weekday)) {
            $weekdayTotal = 0;
            $peakWeekday = null;
            foreach ($weekday as $row) {
                $count = (int) ($row['sessions_count'] ?? 0);
                $weekdayTotal += $count;
                if ($peakWeekday === null || $count > (int) ($peakWeekday['sessions_count'] ?? 0)) {
                    $peakWeekday = $row;
                }
            }

            $peakWeekdayCount = (int) ($peakWeekday['sessions_count'] ?? 0);
            $peakWeekdayShare = $weekdayTotal > 0 ? round(($peakWeekdayCount / $weekdayTotal) * 100, 1) : 0.0;
            if ($weekdayTotal >= 30 && $peakWeekdayShare >= 40) {
                $this->pushInsight(
                    $insights,
                    'weekday_imbalance',
                    'opportunity',
                    3,
                    ['peak_weekday_share_pct' => $peakWeekdayShare],
                    ['weekday_promo', 'off_peak_promo', 'marketing_push'],
                    'medium',
                    65,
                    [
                        $this->evidence('peak_weekday', (string) ($peakWeekday['label'] ?? '-'), null),
                        $this->evidence('peak_weekday_share', $peakWeekdayShare, '%'),
                    ],
                );
            }
        }
    }

    private function addZoneInsights(array &$insights, array $zones, array $zonePcMap, int $totalPcs): void
    {
        $zonesItems = is_array($zones['items'] ?? null) ? $zones['items'] : [];
        if (empty($zonesItems)) {
            return;
        }

        $totalRevenue = 0;
        $topZone = null;
        $lowZone = null;
        foreach ($zonesItems as $row) {
            $revenue = (int) ($row['revenue'] ?? 0);
            $totalRevenue += $revenue;
            if ($topZone === null || $revenue > (int) ($topZone['revenue'] ?? 0)) {
                $topZone = $row;
            }
            if ($lowZone === null || $revenue < (int) ($lowZone['revenue'] ?? 0)) {
                $lowZone = $row;
            }
        }

        if ($totalRevenue > 0 && $topZone) {
            $topShare = round(((int) ($topZone['revenue'] ?? 0) / $totalRevenue) * 100, 1);
            if ($topShare >= 60) {
                $this->pushInsight(
                    $insights,
                    'zone_imbalance',
                    'opportunity',
                    3,
                    ['top_zone_share_pct' => $topShare],
                    ['price_tune', 'optimize_layout'],
                    'medium',
                    65,
                    [
                        $this->evidence('top_zone', (string) ($topZone['zone'] ?? '-'), null),
                        $this->evidence('top_zone_share', $topShare, '%'),
                    ],
                );
            }
        }

        if ($totalRevenue <= 0 || !$topZone || !$lowZone || (($topZone['zone'] ?? '') === ($lowZone['zone'] ?? ''))) {
            return;
        }

        $topShare = round(((int) ($topZone['revenue'] ?? 0) / $totalRevenue) * 100, 1);
        $lowShare = round(((int) ($lowZone['revenue'] ?? 0) / $totalRevenue) * 100, 1);
        $gap = max(0.0, $topShare - $lowShare);
        $topZoneName = (string) ($topZone['zone'] ?? '-');
        $lowZoneName = (string) ($lowZone['zone'] ?? '-');
        $topZonePcs = $zonePcMap[$topZoneName] ?? 0;
        $lowZonePcs = $zonePcMap[$lowZoneName] ?? 0;
        $ratio = $gap > 0 ? min(0.25, max(0.05, $gap / 200)) : 0.05;
        $movePcs = $totalPcs > 0 ? max(1, (int) round($totalPcs * $ratio)) : 1;
        $impact = $gap >= 25 ? 'high' : ($gap >= 12 ? 'medium' : 'low');
        $priority = $gap >= 25 ? 2 : ($gap >= 12 ? 3 : 4);
        $confidence = $gap >= 25 ? 75 : ($gap >= 12 ? 65 : 55);

        $this->pushInsight(
            $insights,
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
                $this->evidence('top_zone', $topZoneName, null),
                $this->evidence('top_zone_share', $topShare, '%'),
                $this->evidence('top_zone_revenue', (int) ($topZone['revenue'] ?? 0), 'UZS'),
                $this->evidence('top_zone_pcs', (int) $topZonePcs, null),
                $this->evidence('low_zone', $lowZoneName, null),
                $this->evidence('low_zone_share', $lowShare, '%'),
                $this->evidence('low_zone_revenue', (int) ($lowZone['revenue'] ?? 0), 'UZS'),
                $this->evidence('low_zone_pcs', (int) $lowZonePcs, null),
                $this->evidence('move_pcs', $movePcs, null),
            ],
        );
    }

    private function addOperatorInsights(array &$insights, array $operators): void
    {
        $items = is_array($operators['items'] ?? null) ? $operators['items'] : [];
        if (count($items) < 3) {
            return;
        }

        $totalSales = 0;
        $topOperator = null;
        foreach ($items as $row) {
            $salesAmount = (int) ($row['sales_amount'] ?? 0) + (int) ($row['sessions_revenue'] ?? 0);
            $totalSales += $salesAmount;
            if (
                $topOperator === null
                || $salesAmount > ((int) ($topOperator['sales_amount'] ?? 0) + (int) ($topOperator['sessions_revenue'] ?? 0))
            ) {
                $topOperator = $row;
            }
        }

        $avgSales = $totalSales / max(1, count($items));
        if (!$topOperator || $avgSales <= 0) {
            return;
        }

        $topSales = (int) ($topOperator['sales_amount'] ?? 0) + (int) ($topOperator['sessions_revenue'] ?? 0);
        if ($topSales < $avgSales * 2.5) {
            return;
        }

        $this->pushInsight(
            $insights,
            'operator_variance',
            'opportunity',
            4,
            ['top_operator_share_pct' => round(($topSales / $totalSales) * 100, 1)],
            ['staff_training', 'staff_peak'],
            'medium',
            60,
            [
                $this->evidence('operators', (string) ($topOperator['operator'] ?? '-'), null),
                $this->evidence('revenue', $topSales, 'UZS'),
            ],
        );
    }

    private function buildKpisPayload(array $metrics): array
    {
        return [
            'gross_sales' => (int) ($metrics['gross_sales'] ?? 0),
            'net_sales' => (int) ($metrics['net_sales'] ?? 0),
            'sessions_count' => (int) ($metrics['sessions_count'] ?? 0),
            'utilization_pct' => round((float) ($metrics['utilization_pct'] ?? 0), 1),
            'avg_session_minutes' => round((float) ($metrics['avg_session_minutes'] ?? 0), 1),
            'returns_ratio_pct' => (float) ($metrics['returns_ratio_pct'] ?? 0),
            'expenses_ratio_pct' => (float) ($metrics['expenses_ratio_pct'] ?? 0),
            'package_share_pct' => (float) ($metrics['package_share_pct'] ?? 0),
            'new_clients_ratio_pct' => (float) ($metrics['new_clients_ratio_pct'] ?? 0),
            'card_share_pct' => (float) ($metrics['card_share_pct'] ?? 0),
            'live_occupancy_pct' => (float) ($metrics['live_occupancy_pct'] ?? 0),
            'active_sessions_now' => (int) ($metrics['active_sessions_now'] ?? 0),
            'pcs_online' => (int) ($metrics['pcs_online'] ?? 0),
            'cash_sales_total' => (int) ($metrics['cash_sales_total'] ?? 0),
            'card_sales_total' => (int) ($metrics['card_sales_total'] ?? 0),
            'balance_sales_total' => (int) ($metrics['balance_sales_total'] ?? 0),
            'topup_total' => (int) ($metrics['topup_total'] ?? 0),
        ];
    }

    private function buildZonePcInventory(int $tenantId): array
    {
        $zoneExpr = "COALESCE(NULLIF(TRIM(COALESCE(zone,'')), ''), 'No zone')";
        $rows = Pc::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            })
            ->selectRaw("{$zoneExpr} AS zone_name, COUNT(*) AS pcs_count")
            ->groupByRaw($zoneExpr)
            ->get();

        $zonePcMap = [];
        $totalPcs = 0;
        foreach ($rows as $row) {
            $zoneName = (string) $row->zone_name;
            $count = (int) $row->pcs_count;
            $zonePcMap[$zoneName] = $count;
            $totalPcs += $count;
        }

        return [$zonePcMap, $totalPcs];
    }

    private function pushInsight(
        array &$insights,
        string $id,
        string $type,
        int $priority,
        array $metrics = [],
        array $actions = [],
        string $impact = 'medium',
        int $confidence = 60,
        array $evidence = [],
    ): void {
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
    }

    private function evidence(string $key, mixed $value, ?string $unit = null): array
    {
        return [
            'key' => $key,
            'value' => $value,
            'unit' => $unit,
        ];
    }

    private function sortInsights(array &$insights): void
    {
        usort($insights, static function (array $left, array $right): int {
            $leftPriority = (int) ($left['priority'] ?? 5);
            $rightPriority = (int) ($right['priority'] ?? 5);
            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            $leftType = self::INSIGHT_TYPE_WEIGHT[$left['type'] ?? 'info'] ?? 9;
            $rightType = self::INSIGHT_TYPE_WEIGHT[$right['type'] ?? 'info'] ?? 9;

            return $leftType <=> $rightType;
        });
    }

    private function formatRange(Carbon $from, Carbon $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $from->diffInDays($to) + 1,
        ];
    }
}
