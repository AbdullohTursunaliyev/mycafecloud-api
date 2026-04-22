<?php

namespace App\Http\Resources\Layout;

use App\Http\Resources\BaseJsonResource;
use App\Models\PcCell;

class LayoutCellResource extends BaseJsonResource
{
    /**
     * @var PcCell
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'row' => (int) $this->resource->row,
            'col' => (int) $this->resource->col,
            'zone_id' => $this->resource->zone_id ? (int) $this->resource->zone_id : null,
            'zone' => $this->resource->zone?->name,
            'pc_id' => $this->resource->pc_id ? (int) $this->resource->pc_id : null,
            'pc' => $this->resource->pc ? [
                'id' => (int) $this->resource->pc->id,
                'code' => (string) $this->resource->pc->code,
                'status' => (string) $this->resource->pc->status,
                'zone_id' => $this->resource->pc->zone_id ? (int) $this->resource->pc->zone_id : null,
            ] : null,
            'label' => $this->resource->label,
            'is_active' => (bool) $this->resource->is_active,
            'notes' => $this->resource->notes,
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
