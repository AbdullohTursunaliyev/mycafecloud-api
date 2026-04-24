<?php

namespace App\Http\Resources\Saas;

use App\Http\Resources\BaseJsonResource;
use App\Models\Tenant;
use App\Services\TenantFeatureService;

class TenantResource extends BaseJsonResource
{
    /**
     * @var Tenant
     */
    public $resource;

    public function toArray($request): array
    {
        $features = app(TenantFeatureService::class);
        $plan = $features->planPayload(
            $this->resource,
            isset($this->resource->pcs_count) ? (int) $this->resource->pcs_count : null,
        );

        return [
            'id' => (int) $this->resource->id,
            'name' => (string) $this->resource->name,
            'status' => (string) $this->resource->status,
            'pc_count' => isset($this->resource->pcs_count)
                ? (int) $this->resource->pcs_count
                : null,
            'license_keys_count' => isset($this->resource->license_keys_count)
                ? (int) $this->resource->license_keys_count
                : null,
            'saas_plan' => $plan,
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
