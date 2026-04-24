<?php

namespace App\Services;

use App\Models\SaasPlan;
use App\Models\Tenant;
use Illuminate\Support\Collection;

class TenantFeatureService
{
    private const DEFAULT_PLAN_CODE = 'basic';

    private const DEFAULT_FEATURES = [
        'basic' => [
            'nexora_ai' => false,
            'ai_generation' => false,
            'ai_insights' => false,
            'ai_autopilot' => false,
        ],
        'pro' => [
            'nexora_ai' => true,
            'ai_generation' => true,
            'ai_insights' => true,
            'ai_autopilot' => true,
        ],
    ];

    public function ensureDefaultPlans(): void
    {
        $definitions = [
            'basic' => [
                'name' => 'Basic',
                'status' => 'active',
                'price_per_pc_uzs' => 0,
                'features' => self::DEFAULT_FEATURES['basic'],
                'sort_order' => 10,
            ],
            'pro' => [
                'name' => 'Pro',
                'status' => 'active',
                'price_per_pc_uzs' => 0,
                'features' => self::DEFAULT_FEATURES['pro'],
                'sort_order' => 20,
            ],
        ];

        foreach ($definitions as $code => $attributes) {
            SaasPlan::query()->firstOrCreate(
                ['code' => $code],
                $attributes,
            );
        }
    }

    public function featureEnabledForTenantId(int $tenantId, string $feature): bool
    {
        $tenant = Tenant::query()
            ->with('saasPlan')
            ->find($tenantId);

        return $this->featureEnabledForTenant($tenant, $feature);
    }

    public function featureEnabledForTenant(?Tenant $tenant, string $feature): bool
    {
        if (!$tenant) {
            return false;
        }

        $features = $this->effectiveFeatures($this->resolvePlan($tenant));

        return (bool) ($features[$feature] ?? false);
    }

    public function planPayloadForTenantId(int $tenantId): ?array
    {
        $tenant = Tenant::query()
            ->with(['saasPlan'])
            ->withCount('pcs')
            ->find($tenantId);

        return $this->planPayload($tenant);
    }

    public function planPayload(?Tenant $tenant, ?int $pcCount = null): ?array
    {
        $plan = $this->resolvePlan($tenant);
        if (!$plan) {
            return null;
        }

        $count = $pcCount;
        if ($count === null && $tenant) {
            $count = isset($tenant->pcs_count)
                ? (int) $tenant->pcs_count
                : $tenant->pcs()->count();
        }

        $count = (int) ($count ?? 0);
        $pricePerPc = (int) ($plan->price_per_pc_uzs ?? 0);

        return [
            'id' => (int) $plan->id,
            'code' => (string) $plan->code,
            'name' => (string) $plan->name,
            'status' => (string) $plan->status,
            'price_per_pc_uzs' => $pricePerPc,
            'pc_count' => $count,
            'estimated_monthly_price_uzs' => $pricePerPc * $count,
            'features' => $this->effectiveFeatures($plan),
        ];
    }

    public function listFeatureFlags(?SaasPlan $plan): array
    {
        return $this->effectiveFeatures($plan);
    }

    public function defaultPlanId(): ?int
    {
        $this->ensureDefaultPlans();

        return SaasPlan::query()
            ->where('code', self::DEFAULT_PLAN_CODE)
            ->value('id');
    }

    public function featureDeniedMessage(string $feature, string $locale = 'uz'): string
    {
        $messages = [
            'uz' => [
                'nexora_ai' => 'Bu funksiya Pro tarifda mavjud. Nexora AI uchun klubni Pro tarifga o‘tkazing.',
                'ai_generation' => 'AI generatsiya Pro tarifda mavjud. Bu funksiyani yoqish uchun klubni Pro tarifga o‘tkazing.',
                'ai_insights' => 'AI insightlar Pro tarifda mavjud. Bu bo‘lim uchun klubni Pro tarifga o‘tkazing.',
                'ai_autopilot' => 'AI autopilot Pro tarifda mavjud. Bu funksiyani yoqish uchun klubni Pro tarifga o‘tkazing.',
            ],
            'ru' => [
                'nexora_ai' => 'Эта функция доступна только в тарифе Pro. Переведите клуб на Pro, чтобы использовать Nexora AI.',
                'ai_generation' => 'AI-генерация доступна только в тарифе Pro. Переведите клуб на Pro, чтобы включить эту функцию.',
                'ai_insights' => 'AI-инсайты доступны только в тарифе Pro. Переведите клуб на Pro, чтобы открыть этот раздел.',
                'ai_autopilot' => 'AI-autopilot доступен только в тарифе Pro. Переведите клуб на Pro, чтобы включить эту функцию.',
            ],
            'en' => [
                'nexora_ai' => 'This feature is available only on the Pro plan. Upgrade the club to Pro to use Nexora AI.',
                'ai_generation' => 'AI generation is available only on the Pro plan. Upgrade the club to Pro to enable this feature.',
                'ai_insights' => 'AI insights are available only on the Pro plan. Upgrade the club to Pro to open this section.',
                'ai_autopilot' => 'AI autopilot is available only on the Pro plan. Upgrade the club to Pro to enable this feature.',
            ],
        ];

        $locale = in_array($locale, ['uz', 'ru', 'en'], true) ? $locale : 'uz';

        return $messages[$locale][$feature]
            ?? $messages[$locale]['nexora_ai'];
    }

    public function activePlans(): Collection
    {
        $this->ensureDefaultPlans();

        return SaasPlan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function resolvePlan(?Tenant $tenant): ?SaasPlan
    {
        $this->ensureDefaultPlans();

        if ($tenant?->relationLoaded('saasPlan') && $tenant->saasPlan) {
            return $tenant->saasPlan;
        }

        if ($tenant?->saas_plan_id) {
            return SaasPlan::query()->find($tenant->saas_plan_id);
        }

        return SaasPlan::query()
            ->where('code', self::DEFAULT_PLAN_CODE)
            ->first();
    }

    private function effectiveFeatures(?SaasPlan $plan): array
    {
        if (!$plan) {
            return self::DEFAULT_FEATURES[self::DEFAULT_PLAN_CODE];
        }

        $defaults = self::DEFAULT_FEATURES[(string) $plan->code] ?? [];
        $stored = is_array($plan->features) ? $plan->features : [];

        return array_replace($defaults, $stored);
    }
}
