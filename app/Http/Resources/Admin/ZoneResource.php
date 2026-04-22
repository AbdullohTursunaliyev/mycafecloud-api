<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;

class ZoneResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'name' => (string) $this->resource->name,
            'price_per_hour' => (int) $this->resource->price_per_hour,
            'is_active' => (bool) $this->resource->is_active,
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
