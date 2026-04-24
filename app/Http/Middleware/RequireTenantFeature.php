<?php

namespace App\Http\Middleware;

use App\Services\TenantFeatureService;
use Closure;
use Illuminate\Http\Request;

class RequireTenantFeature
{
    public function __construct(
        private readonly TenantFeatureService $features,
    ) {
    }

    public function handle(Request $request, Closure $next, string $feature)
    {
        $actor = $request->user('operator') ?: $request->user();
        $tenantId = (int) ($actor->tenant_id ?? 0);
        $locale = (string) ($request->input('locale') ?: $request->query('locale', 'uz'));

        if ($tenantId <= 0 || !$this->features->featureEnabledForTenantId($tenantId, $feature)) {
            return response()->json([
                'message' => $this->features->featureDeniedMessage($feature, $locale),
                'feature' => $feature,
                'plan' => $tenantId > 0 ? $this->features->planPayloadForTenantId($tenantId) : null,
                'upgrade_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
