<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;

class PromotionResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'name' => (string) $this->resource->name,
            'type' => (string) $this->resource->type,
            'applies_payment_method' => (string) $this->resource->applies_payment_method,
            'days_of_week' => is_array($this->resource->days_of_week) ? array_values($this->resource->days_of_week) : null,
            'time_from' => $this->resource->time_from ? (string) $this->resource->time_from : null,
            'time_to' => $this->resource->time_to ? (string) $this->resource->time_to : null,
            'starts_at' => optional($this->resource->starts_at)?->toIso8601String(),
            'ends_at' => optional($this->resource->ends_at)?->toIso8601String(),
            'priority' => (int) $this->resource->priority,
            'is_active' => (bool) $this->resource->is_active,
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
