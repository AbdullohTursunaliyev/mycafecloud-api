<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;

class PackageResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'name' => (string) $this->resource->name,
            'duration_min' => (int) $this->resource->duration_min,
            'price' => (int) $this->resource->price,
            'zone' => (string) $this->resource->zone,
            'is_active' => (bool) $this->resource->is_active,
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
