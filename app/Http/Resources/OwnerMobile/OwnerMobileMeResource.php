<?php

namespace App\Http\Resources\OwnerMobile;

use App\Http\Resources\BaseJsonResource;
use App\Models\Operator;

class OwnerMobileMeResource extends BaseJsonResource
{
    /**
     * @var Operator
     */
    public $resource;

    public function toArray($request): array
    {
        return [
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
