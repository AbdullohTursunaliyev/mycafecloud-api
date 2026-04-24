<?php

namespace App\Services;

use App\Models\Pc;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SaasReportService
{
    public function __construct(
        private readonly TenantFeatureService $features,
    ) {
    }

    public function overview(): array
    {
        $this->features->ensureDefaultPlans();

        $tenants = Tenant::query()
            ->with(['saasPlan'])
            ->withCount(['pcs'])
            ->orderBy('created_at')
            ->get();

        $activeTenants = $tenants
            ->where('status', 'active')
            ->values();

        $now = CarbonImmutable::now();
        $currentMonthStart = $now->startOfMonth();
        $previousMonthStart = $currentMonthStart->subMonth();

        $currentMrr = $this->sumMrr($activeTenants);
        $previousMrr = $this->sumMrr(
            $activeTenants->filter(fn (Tenant $tenant): bool => $tenant->created_at?->lt($currentMonthStart) ?? false)
        );
        $growthValue = $currentMrr - $previousMrr;
        $growthPercent = $previousMrr > 0
            ? round(($growthValue / $previousMrr) * 100, 1)
            : ($currentMrr > 0 ? 100.0 : 0.0);

        $connectedPcs = (int) $tenants->sum('pcs_count');
        $onlinePcs = (int) Pc::query()->where('status', 'online')->count();
        $thisMonthNewTenants = (int) $tenants
            ->filter(fn (Tenant $tenant): bool => $tenant->created_at?->gte($currentMonthStart) ?? false)
            ->count();
        $lastMonthNewTenants = (int) $tenants
            ->filter(fn (Tenant $tenant): bool => ($tenant->created_at?->gte($previousMonthStart) ?? false) && ($tenant->created_at?->lt($currentMonthStart) ?? false))
            ->count();
        $thisMonthNewMrr = $this->sumMrr(
            $activeTenants->filter(fn (Tenant $tenant): bool => $tenant->created_at?->gte($currentMonthStart) ?? false)
        );

        return [
            'generated_at' => $now->toIso8601String(),
            'metrics' => [
                'current_mrr_uzs' => $currentMrr,
                'previous_mrr_uzs' => $previousMrr,
                'mrr_growth_uzs' => $growthValue,
                'mrr_growth_percent' => $growthPercent,
                'arr_uzs' => $currentMrr * 12,
                'total_tenants' => (int) $tenants->count(),
                'active_tenants' => (int) $activeTenants->count(),
                'basic_tenants' => (int) $tenants->filter(fn (Tenant $tenant): bool => $tenant->saasPlan?->code === 'basic')->count(),
                'pro_tenants' => (int) $tenants->filter(fn (Tenant $tenant): bool => $tenant->saasPlan?->code === 'pro')->count(),
                'connected_pcs' => $connectedPcs,
                'online_pcs' => $onlinePcs,
                'avg_mrr_per_tenant_uzs' => $activeTenants->count() > 0
                    ? (int) round($currentMrr / $activeTenants->count())
                    : 0,
                'this_month_new_tenants' => $thisMonthNewTenants,
                'last_month_new_tenants' => $lastMonthNewTenants,
                'this_month_new_mrr_uzs' => $thisMonthNewMrr,
            ],
            'plan_mix' => $this->buildPlanMix($tenants),
            'trend' => $this->buildTrend($tenants, $activeTenants, $now),
            'recent_tenants' => $this->buildRecentTenants($tenants),
        ];
    }

    private function buildPlanMix(Collection $tenants): array
    {
        return $this->features->activePlans()
            ->map(function ($plan) use ($tenants): array {
                $planTenants = $tenants
                    ->filter(fn (Tenant $tenant): bool => (int) $tenant->saas_plan_id === (int) $plan->id)
                    ->values();

                $activePlanTenants = $planTenants
                    ->where('status', 'active')
                    ->values();

                return [
                    'id' => (int) $plan->id,
                    'code' => (string) $plan->code,
                    'name' => (string) $plan->name,
                    'status' => (string) $plan->status,
                    'price_per_pc_uzs' => (int) $plan->price_per_pc_uzs,
                    'tenant_count' => (int) $planTenants->count(),
                    'active_tenant_count' => (int) $activePlanTenants->count(),
                    'connected_pcs' => (int) $planTenants->sum('pcs_count'),
                    'mrr_uzs' => $this->sumMrr($activePlanTenants),
                ];
            })
            ->values()
            ->all();
    }

    private function buildTrend(Collection $tenants, Collection $activeTenants, CarbonImmutable $now): array
    {
        $points = [];

        for ($offset = 5; $offset >= 0; $offset--) {
            $monthStart = $now->startOfMonth()->subMonths($offset);
            $monthEnd = $monthStart->endOfMonth();

            $activeByMonth = $activeTenants
                ->filter(fn (Tenant $tenant): bool => $tenant->created_at?->lte($monthEnd) ?? false)
                ->values();

            $newByMonth = $tenants
                ->filter(fn (Tenant $tenant): bool => ($tenant->created_at?->gte($monthStart) ?? false) && ($tenant->created_at?->lte($monthEnd) ?? false))
                ->values();

            $points[] = [
                'month' => $monthStart->format('Y-m'),
                'label' => $monthStart->format('m.Y'),
                'mrr_uzs' => $this->sumMrr($activeByMonth),
                'tenant_count' => (int) $activeByMonth->count(),
                'connected_pcs' => (int) $activeByMonth->sum('pcs_count'),
                'new_tenants' => (int) $newByMonth->count(),
            ];
        }

        return $points;
    }

    private function buildRecentTenants(Collection $tenants): array
    {
        return $tenants
            ->sortByDesc(fn (Tenant $tenant) => $tenant->created_at?->getTimestamp() ?? 0)
            ->take(5)
            ->map(function (Tenant $tenant): array {
                $planPayload = $this->features->planPayload($tenant, (int) $tenant->pcs_count);

                return [
                    'id' => (int) $tenant->id,
                    'name' => (string) $tenant->name,
                    'status' => (string) $tenant->status,
                    'created_at' => $tenant->created_at?->toIso8601String(),
                    'pc_count' => (int) $tenant->pcs_count,
                    'saas_plan' => $planPayload,
                ];
            })
            ->values()
            ->all();
    }

    private function sumMrr(Collection $tenants): int
    {
        return (int) $tenants->sum(function (Tenant $tenant): int {
            return (int) ($this->features->planPayload($tenant, (int) $tenant->pcs_count)['estimated_monthly_price_uzs'] ?? 0);
        });
    }
}
