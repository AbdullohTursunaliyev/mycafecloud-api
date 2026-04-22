<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;

class SubscriptionPlanResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        $zone = $this->resource->relationLoaded('zone') ? $this->resource->zone : null;

        return [
            'id' => (int) $this->resource->id,
            'name' => (string) $this->resource->name,
            'zone_id' => (int) $this->resource->zone_id,
            'zone' => $zone ? [
                'id' => (int) $zone->id,
                'name' => (string) $zone->name,
            ] : null,
            'duration_days' => (int) $this->resource->duration_days,
            'price' => (int) $this->resource->price,
            'is_active' => (bool) $this->resource->is_active,
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
