<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;

class ZonePricingWindowResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'zone_id' => (int) $this->resource->zone_id,
            'name' => $this->resource->name,
            'starts_at' => (string) $this->resource->starts_at,
            'ends_at' => (string) $this->resource->ends_at,
            'starts_on' => optional($this->resource->starts_on)?->toDateString(),
            'ends_on' => optional($this->resource->ends_on)?->toDateString(),
            'weekdays' => array_values(array_map('intval', (array) ($this->resource->weekdays ?? []))),
            'price_per_hour' => (int) $this->resource->price_per_hour,
            'is_active' => (bool) $this->resource->is_active,
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
