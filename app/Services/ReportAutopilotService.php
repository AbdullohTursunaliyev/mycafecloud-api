<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Pc;
use App\Models\Promotion;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportAutopilotService
{
    private const STRATEGY_PRESETS = [
        'balanced' => ['max_up' => 12, 'max_down' => 10, 'promo_uplift' => 8],
        'growth' => ['max_up' => 18, 'max_down' => 12, 'promo_uplift' => 12],
        'aggressive' => ['max_up' => 25, 'max_down' => 15, 'promo_uplift' => 16],
    ];

    public function __construct(
        private readonly TenantReportService $reports,
        private readonly TenantSettingService $settings,
        private readonly EventLogger $events,
    ) {}

    public function buildPlan(int $tenantId, Carbon $from, Carbon $to, string $strategy): array
    {
        $strategy = $this->validateStrategy($strategy);
        $report = $this->reports->build($tenantId, $from, $to);
        $summary = (array) ($report['summary'] ?? []);
        $activity = (array) ($report['activity'] ?? []);
        $sales = (array) ($report['sales'] ?? []);
        $insights = (array) ($report['insights'] ?? []);
        $sessions = (array) ($report['sessions'] ?? []);
        $daily = (array) ($report['daily'] ?? []);
        $periodDays = max(1, (int) ($report['period']['days'] ?? ($from->diffInDays($to) + 1)));
        $overallUtilization = (float) ($summary['utilization_pct'] ?? 0);
        $pcsTotal = max(1, (int) ($activity['pcs_total'] ?? 0));
        $preset = self::STRATEGY_PRESETS[$strategy];
        $avgSessionMinutes = max(1, (float) ($summary['avg_session_minutes'] ?? 60));

        $hourlyInsights = collect($sessions['hourly_distribution'] ?? [])
            ->map(function ($row) use ($pcsTotal, $periodDays, $avgSessionMinutes) {
                $row = is_array($row) ? $row : [];
                $sessionsCount = (int) ($row['sessions_count'] ?? 0);
                $capacityMinutes = max(1, $pcsTotal * $periodDays * 60);
                $estimatedMinutes = $sessionsCount * $avgSessionMinutes;

                return [
                    'hour' => (int) ($row['hour'] ?? 0),
                    'label' => (string) ($row['label'] ?? '00:00'),
                    'sessions_count' => $sessionsCount,
                    'revenue' => (int) ($row['revenue'] ?? 0),
                    'occupancy_pct' => round(min(100, ($estimatedMinutes / $capacityMinutes) * 100), 1),
                ];
            })
            ->values();

        $peakHours = $hourlyInsights
            ->sortByDesc('occupancy_pct')
            ->take(4)
            ->values()
            ->all();

        $lowHours = $hourlyInsights
            ->sortBy(static function ($row) {
                $row = is_array($row) ? $row : [];

                return (((float) $row['occupancy_pct']) * 10000)
                    + (((int) $row['sessions_count']) * 10)
                    + ((int) $row['hour'] / 100);
            })
            ->take(4)
            ->values()
            ->all();

        $zoneUpdates = $this->buildZoneAutopilotRecommendations(
            $tenantId,
            (array) ($report['zones']['items'] ?? []),
            $periodDays,
            $overallUtilization,
            $preset,
        );

        $promotionPlan = $this->buildPromotionAutopilotPlan(
            $hourlyInsights->all(),
            $sessions['weekday_distribution'] ?? [],
            $sales,
            $periodDays,
            $preset,
            $strategy,
        );

        $zoneMonthlyUplift = (int) collect($zoneUpdates)->sum('expected_monthly_uplift');
        $promoMonthlyUplift = (int) ($promotionPlan['expected_monthly_uplift'] ?? 0);
        $scenario = $this->buildAutopilotScenarios(
            (float) ($insights['projection']['daily_average_net'] ?? 0),
            $zoneMonthlyUplift,
            $promoMonthlyUplift,
        );

        $confidence = $this->clampInt(
            (int) round(45 + min(30, count($daily)) + min(20, ((int) ($summary['sessions_count'] ?? 0)) / 25)),
            45,
            95,
        );

        $beastState = $this->settings->get($tenantId, 'beast_mode', ['enabled' => false]);
        if (!is_array($beastState)) {
            $beastState = ['enabled' => false];
        }

        $beastMode = $this->buildBeastModeInsights(
            $report,
            $scenario,
            $promotionPlan,
            $strategy,
            (bool) ($beastState['enabled'] ?? false),
            $periodDays,
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
                'net_sales' => (int) ($summary['net_sales'] ?? 0),
                'gross_sales' => (int) ($summary['gross_sales'] ?? 0),
                'sessions_count' => (int) ($summary['sessions_count'] ?? 0),
                'utilization_pct' => $overallUtilization,
                'avg_session_minutes' => (float) ($summary['avg_session_minutes'] ?? 0),
                'daily_average_net' => (float) ($insights['projection']['daily_average_net'] ?? 0),
                'yearly_from_average' => (int) ($insights['projection']['yearly_from_average'] ?? 0),
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
                'promotion_enabled' => (bool) ($promotionPlan['enabled'] ?? false),
            ],
        ];
    }

    public function applyPlan(
        int $tenantId,
        int $operatorId,
        Carbon $from,
        Carbon $to,
        string $strategy,
        bool $applyZonePrices = true,
        bool $applyPromotion = true,
        bool $enableBeastMode = true,
        bool $dryRun = false,
    ): array {
        $strategy = $this->validateStrategy($strategy);
        $plan = $this->buildPlan($tenantId, $from, $to, $strategy);

        if ($dryRun) {
            return [
                'applied' => false,
                'dry_run' => true,
                'strategy' => $strategy,
                'plan' => $plan,
            ];
        }

        $appliedZoneUpdates = [];
        $appliedPromotionResult = null;
        $appliedBeastModeState = null;
        $zoneUpdates = is_array($plan['actions']['zone_price_updates'] ?? null)
            ? $plan['actions']['zone_price_updates']
            : [];
        $promotionPlan = is_array($plan['actions']['promotion'] ?? null)
            ? $plan['actions']['promotion']
            : null;
        $beastPlan = is_array($plan['beast_mode'] ?? null)
            ? $plan['beast_mode']
            : [];

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
            &$appliedBeastModeState,
        ) {
            if ($applyZonePrices) {
                foreach ($zoneUpdates as $item) {
                    $item = is_array($item) ? $item : [];
                    $zone = Zone::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey((int) ($item['zone_id'] ?? 0))
                        ->lockForUpdate()
                        ->first();

                    if (!$zone) {
                        continue;
                    }

                    $oldPrice = (int) $zone->price_per_hour;
                    $newPrice = (int) ($item['recommended_price_per_hour'] ?? $oldPrice);
                    if ($newPrice <= 0 || $newPrice === $oldPrice) {
                        continue;
                    }

                    $zone->price_per_hour = $newPrice;
                    $zone->save();

                    $appliedZoneUpdates[] = [
                        'zone_id' => (int) $zone->id,
                        'zone_name' => (string) $zone->name,
                        'old_price_per_hour' => $oldPrice,
                        'new_price_per_hour' => $newPrice,
                        'delta_pct' => $oldPrice > 0
                            ? round((($newPrice - $oldPrice) / $oldPrice) * 100, 1)
                            : null,
                    ];
                }
            }

            if ($applyPromotion && is_array($promotionPlan) && !empty($promotionPlan['enabled'])) {
                $promoPayload = [
                    'name' => (string) ($promotionPlan['name'] ?? 'AI Booster'),
                    'type' => 'double_topup',
                    'is_active' => true,
                    'days_of_week' => !empty($promotionPlan['days_of_week'])
                        ? array_values($promotionPlan['days_of_week'])
                        : null,
                    'time_from' => !empty($promotionPlan['time_from']) ? (string) $promotionPlan['time_from'] : null,
                    'time_to' => !empty($promotionPlan['time_to']) ? (string) $promotionPlan['time_to'] : null,
                    'applies_payment_method' => PaymentMethod::Cash->value,
                    'starts_at' => !empty($promotionPlan['starts_at']) ? Carbon::parse($promotionPlan['starts_at']) : null,
                    'ends_at' => !empty($promotionPlan['ends_at']) ? Carbon::parse($promotionPlan['ends_at']) : null,
                    'priority' => (int) ($promotionPlan['priority'] ?? 5),
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
                        'id' => (int) $existing->id,
                        'action' => 'updated',
                        'name' => (string) $existing->name,
                        'time_from' => $existing->time_from,
                        'time_to' => $existing->time_to,
                    ];
                } else {
                    $created = Promotion::query()->create($promoPayload + [
                        'tenant_id' => $tenantId,
                    ]);

                    $appliedPromotionResult = [
                        'id' => (int) $created->id,
                        'action' => 'created',
                        'name' => (string) $created->name,
                        'time_from' => $created->time_from,
                        'time_to' => $created->time_to,
                    ];
                }
            }

            $policy = [
                'enabled' => $enableBeastMode,
                'strategy' => (string) ($beastPlan['strategy'] ?? 'balanced'),
                'profit_guard' => $beastPlan['profit_guard'] ?? null,
                'energy_optimizer' => $beastPlan['energy_optimizer'] ?? null,
                'leak_watch' => $beastPlan['leak_watch'] ?? null,
                'updated_at' => now()->toDateTimeString(),
            ];
            $this->settings->set($tenantId, 'beast_mode', $policy);
            $appliedBeastModeState = $policy;
        });

        try {
            $this->events->log(
                $tenantId,
                'autopilot_applied',
                'operator',
                'operator',
                $operatorId,
                [
                    'strategy' => $strategy,
                    'zones_updated_count' => count($appliedZoneUpdates),
                    'promotion_applied' => $appliedPromotionResult !== null,
                    'beast_mode_enabled' => (bool) ($appliedBeastModeState['enabled'] ?? false),
                ],
            );
        } catch (\Throwable) {
            // Do not fail the request if logging fails.
        }

        return [
            'applied' => true,
            'strategy' => $strategy,
            'zone_updates' => $appliedZoneUpdates,
            'promotion' => $appliedPromotionResult,
            'summary' => [
                'zones_updated_count' => count($appliedZoneUpdates),
                'promotion_applied' => $appliedPromotionResult !== null,
                'beast_mode_enabled' => (bool) ($appliedBeastModeState['enabled'] ?? false),
            ],
            'beast_mode' => $appliedBeastModeState,
            'plan' => $plan,
        ];
    }

    private function buildBeastModeInsights(
        array $report,
        array $scenario,
        array $promotionPlan,
        string $strategy,
        bool $enabled,
        int $periodDays,
    ): array {
        $summary = (array) ($report['summary'] ?? []);
        $activity = (array) ($report['activity'] ?? []);
        $payments = (array) ($report['payments'] ?? []);
        $operators = (array) ($report['operators']['items'] ?? []);
        $daily = collect($report['daily'] ?? []);
        $grossSales = (int) ($summary['gross_sales'] ?? 0);
        $netSales = (int) ($summary['net_sales'] ?? 0);
        $returnsTotal = (int) ($summary['returns_total'] ?? 0);
        $expensesTotal = (int) ($summary['expenses_total'] ?? 0);
        $returnsRatio = $grossSales > 0 ? round(($returnsTotal / $grossSales) * 100, 2) : 0.0;
        $expensesRatio = $grossSales > 0 ? round(($expensesTotal / $grossSales) * 100, 2) : 0.0;
        $pcsTotal = max(1, (int) ($activity['pcs_total'] ?? 0));
        $pcsOnline = (int) ($activity['pcs_online'] ?? 0);
        $offlinePct = round(max(0, (($pcsTotal - $pcsOnline) / $pcsTotal) * 100), 1);

        $operatorTurnover = collect($operators)
            ->map(static function ($row) {
                $row = is_array($row) ? $row : [];

                return ((int) ($row['sales_amount'] ?? 0)) + ((int) ($row['sessions_revenue'] ?? 0));
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
                max(0, (int) round($grossSales * max(0, ($returnsRatio - 6.0)) / 100 * (30 / max(1, $periodDays)))),
            ),
            $this->buildRiskSignal(
                'expenses_ratio',
                'Xarajat ulushi',
                $expensesRatio . '%',
                22.0,
                $expensesRatio,
                max(0, (int) round($grossSales * max(0, ($expensesRatio - 22.0)) / 100 * (30 / max(1, $periodDays)))),
            ),
            $this->buildRiskSignal(
                'offline_pc_ratio',
                'Offline PC ulushi',
                $offlinePct . '%',
                25.0,
                $offlinePct,
                max(0, (int) round($netSales * max(0, ($offlinePct - 25.0)) / 100 * 0.4 * (30 / max(1, $periodDays)))),
            ),
            $this->buildRiskSignal(
                'operator_concentration',
                'Top operator ulushi',
                $topOperatorShare . '%',
                55.0,
                $topOperatorShare,
                max(0, (int) round($netSales * max(0, ($topOperatorShare - 55.0)) / 100 * 0.25 * (30 / max(1, $periodDays)))),
            ),
        ];

        $riskScore = $this->clampInt(
            (int) round(collect($riskSignals)->sum(static function (array $signal) {
                return match ($signal['status']) {
                    'high' => 26,
                    'medium' => 14,
                    default => 4,
                };
            })),
            10,
            98,
        );

        $leakageMonthly = (int) collect($riskSignals)->sum('impact_uzs');
        $dailyAverageNet = (float) ($scenario['monthly_base'] ?? 0) / 30;
        $dailyMinNet = $daily->count() > 0 ? (float) $daily->min('net_sales') : $dailyAverageNet * 0.65;
        $conservative = collect($scenario['scenarios'] ?? [])->firstWhere('key', 'conservative');
        $expected = collect($scenario['scenarios'] ?? [])->firstWhere('key', 'expected');
        $monthlyFloorBefore = (int) round(max(0, $dailyMinNet) * 30);
        $monthlyFloorAfter = (int) round(
            $monthlyFloorBefore
            + ((int) ($conservative['uplift_monthly'] ?? 0))
            + ($leakageMonthly * 0.22),
        );
        $yearlyFloorAfter = $monthlyFloorAfter * 12;
        $sleepFrom = (string) ($promotionPlan['time_from'] ?? '02:00');
        $sleepTo = (string) ($promotionPlan['time_to'] ?? '07:00');
        $cashSales = (int) ($payments['cash_sales_total'] ?? 0);
        $energyMonthlySavings = (int) round(
            max(1, $pcsTotal) * 16000 * (1 + max(0, ($offlinePct - 10)) / 100),
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
                'expected_monthly_net' => (int) ($expected['monthly_net'] ?? 0),
                'expected_monthly_uplift' => (int) ($expected['uplift_monthly'] ?? 0),
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
                'cashflow_buffer_impact' => (int) round(min($cashSales, $energyMonthlySavings * 0.35)),
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
        int $impactUzs,
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
        array $preset,
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

        $zoneMetricsMap = collect($zoneMetrics)->keyBy(static function ($row) {
            $row = is_array($row) ? $row : [];
            $name = trim((string) ($row['zone'] ?? ''));

            return $name !== '' ? $name : 'No zone';
        });

        $rows = Zone::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price_per_hour']);

        $updates = [];
        foreach ($rows as $zone) {
            $zoneName = trim((string) $zone->name);
            $zoneName = $zoneName !== '' ? $zoneName : 'No zone';
            $currentPrice = max(0, (int) $zone->price_per_hour);
            if ($currentPrice <= 0) {
                continue;
            }

            $metric = $zoneMetricsMap->get($zoneName, []);
            $pcCount = max(0, (int) ($zonePcCounts[$zoneName] ?? 0));
            $zoneMinutes = (int) ($metric['minutes'] ?? 0);
            $zoneRevenue = (int) ($metric['revenue'] ?? 0);
            $zoneUtilization = $pcCount > 0
                ? round(min(100, ($zoneMinutes / max(1, ($pcCount * $periodDays * 24 * 60))) * 100), 1)
                : $overallUtilization;

            $deltaPct = $this->resolvePriceDeltaPct($zoneUtilization, $overallUtilization, $preset);
            if ($deltaPct === 0) {
                continue;
            }

            $recommended = $this->roundPrice((int) round($currentPrice * (1 + ($deltaPct / 100))));
            if ($recommended <= 0 || $recommended === $currentPrice) {
                continue;
            }

            $monthlyFactor = $zoneUtilization >= 60 ? 0.55 : 0.25;
            $expectedMonthlyUplift = (int) round(
                $zoneRevenue * ($deltaPct / 100) * $monthlyFactor * (30 / max(1, $periodDays)),
            );

            $updates[] = [
                'zone_id' => (int) $zone->id,
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
            ->sortByDesc(static function ($row) {
                $row = is_array($row) ? $row : [];

                return abs((float) ($row['delta_pct'] ?? 0));
            })
            ->values()
            ->all();
    }

    private function buildPromotionAutopilotPlan(
        array $hourlyInsights,
        array $weekdayDistribution,
        array $sales,
        int $periodDays,
        array $preset,
        string $strategy,
    ): array {
        $window = $this->resolveLowTrafficWindow($hourlyInsights);
        $topupTotal = (int) ($sales['topup_total'] ?? 0);
        $topupBonusTotal = (int) ($sales['topup_bonus_total'] ?? 0);
        $bonusRatio = $topupTotal > 0 ? round(($topupBonusTotal / $topupTotal) * 100, 2) : 0.0;
        $lowHoursAvg = (float) ($window['avg_occupancy_pct'] ?? 0);
        $enabled = $lowHoursAvg <= 38 || $bonusRatio < 1.5;

        $daysOfWeek = collect($weekdayDistribution)
            ->sortBy(static function ($row) {
                $row = is_array($row) ? $row : [];

                return (((int) ($row['sessions_count'] ?? 0)) * 10)
                    + ((int) ($row['weekday_no'] ?? 0) / 100);
            })
            ->take(4)
            ->map(static fn($row) => (int) (($row['weekday_no'] ?? 0)))
            ->values()
            ->all();
        $daysOfWeek = count($daysOfWeek) >= 7 ? null : $daysOfWeek;

        $expectedTopupUpliftPct = max(4, (int) ($preset['promo_uplift'] ?? 8));
        $expectedMonthlyUplift = (int) round(
            $topupTotal * ($expectedTopupUpliftPct / 100) * 0.35 * (30 / max(1, $periodDays)),
        );

        return [
            'enabled' => $enabled,
            'name' => 'AI Booster ' . $window['time_from'] . '-' . $window['time_to'],
            'type' => 'double_topup',
            'applies_payment_method' => PaymentMethod::Cash->value,
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
        $baseMonthly = (int) round($dailyAverageNet * 30);
        $combinedMonthly = $zoneMonthlyUplift + $promoMonthlyUplift;
        $conservativeMonthly = (int) round($combinedMonthly * 0.45);
        $expectedMonthly = (int) round($combinedMonthly * 0.8);
        $optimisticMonthly = (int) round($combinedMonthly * 1.2);

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

    private function resolveLowTrafficWindow(array $hourlyInsights): array
    {
        $hourMap = collect($hourlyInsights)->keyBy(static fn($row) => (int) ($row['hour'] ?? 0));
        $lowest = collect($hourlyInsights)
            ->sortBy(static function ($row) {
                $row = is_array($row) ? $row : [];

                return (((float) ($row['occupancy_pct'] ?? 0)) * 10000)
                    + (((int) ($row['sessions_count'] ?? 0)) * 10)
                    + ((int) ($row['hour'] ?? 0) / 100);
            })
            ->first();

        $startHour = (int) ($lowest['hour'] ?? 10);
        $hours = [];
        for ($index = 0; $index < 5; $index++) {
            $hours[] = ($startHour + $index) % 24;
        }
        $endHour = ($startHour + 5) % 24;
        $avgOccupancy = collect($hours)
            ->map(static function (int $hour) use ($hourMap) {
                $row = $hourMap->get($hour);

                return (float) ($row['occupancy_pct'] ?? 0);
            })
            ->avg();

        return [
            'hours' => $hours,
            'time_from' => str_pad((string) $startHour, 2, '0', STR_PAD_LEFT) . ':00',
            'time_to' => str_pad((string) $endHour, 2, '0', STR_PAD_LEFT) . ':00',
            'avg_occupancy_pct' => round((float) $avgOccupancy, 1),
        ];
    }

    private function resolvePriceDeltaPct(float $zoneUtilization, float $overallUtilization, array $preset): int
    {
        $maxUp = (int) ($preset['max_up'] ?? 12);
        $maxDown = (int) ($preset['max_down'] ?? 10);
        $delta = 0;

        if ($zoneUtilization >= 85) {
            $delta = $maxUp;
        } elseif ($zoneUtilization >= 70) {
            $delta = (int) round($maxUp * 0.65);
        } elseif ($zoneUtilization <= 20) {
            $delta = -$maxDown;
        } elseif ($zoneUtilization <= 35) {
            $delta = -(int) round($maxDown * 0.6);
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

        return $this->clampInt((int) (round($value / 500) * 500), 5000, 500000);
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function validateStrategy(string $strategy): string
    {
        $strategy = strtolower(trim($strategy));
        if (!array_key_exists($strategy, self::STRATEGY_PRESETS)) {
            throw ValidationException::withMessages([
                'strategy' => 'Strategy must be one of: balanced, growth, aggressive',
            ]);
        }

        return $strategy;
    }
}
