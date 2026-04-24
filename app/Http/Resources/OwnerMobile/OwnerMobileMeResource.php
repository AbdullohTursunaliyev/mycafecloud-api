<?php

namespace App\Http\Resources\OwnerMobile;

use App\Http\Resources\BaseJsonResource;
use App\Models\Operator;
use App\Services\TenantFeatureService;

class OwnerMobileMeResource extends BaseJsonResource
{
    /**
     * @var Operator
     */
    public $resource;

    public function toArray($request): array
    {
        $tenant = $this->resource->tenant()->with('saasPlan')->select('id', 'name', 'status', 'saas_plan_id')->first();
        $features = app(TenantFeatureService::class);

        return [
            'tenant' => $tenant ? [
                'id' => (int) $tenant->id,
                'name' => (string) $tenant->name,
                'status' => (string) $tenant->status,
                'saas_plan' => $features->planPayload($tenant),
            ] : null,
            'operator' => [
                'id' => (int) $this->resource->id,
                'name' => (string) $this->resource->name,
                'login' => (string) $this->resource->login,
                'role' => (string) $this->resource->role,
                'tenant_id' => (int) $this->resource->tenant_id,
            ],
        ];
    }
}
