<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;

class OperatorResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'name' => (string) $this->resource->name,
            'login' => (string) $this->resource->login,
            'role' => (string) $this->resource->role,
            'is_active' => (bool) $this->resource->is_active,
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
