<?php

namespace App\Http\Resources\Saas;

use App\Http\Resources\BaseJsonResource;
use App\Models\SaasPlan;

class SaasPlanResource extends BaseJsonResource
{
    /**
     * @var SaasPlan
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'code' => (string) $this->resource->code,
            'name' => (string) $this->resource->name,
            'status' => (string) $this->resource->status,
            'price_per_pc_uzs' => (int) $this->resource->price_per_pc_uzs,
            'features' => is_array($this->resource->features) ? $this->resource->features : [],
            'sort_order' => (int) ($this->resource->sort_order ?? 0),
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
