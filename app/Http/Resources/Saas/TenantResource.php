<?php

namespace App\Http\Resources\Saas;

use App\Http\Resources\BaseJsonResource;
use App\Models\Tenant;

class TenantResource extends BaseJsonResource
{
    /**
     * @var Tenant
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'name' => (string) $this->resource->name,
            'status' => (string) $this->resource->status,
            'license_keys_count' => isset($this->resource->license_keys_count)
                ? (int) $this->resource->license_keys_count
                : null,
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
