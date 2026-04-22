<?php

namespace App\Http\Resources\Layout;

use App\Http\Resources\BaseJsonResource;

class LayoutIndexResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'grid' => is_array($this->resource['grid'] ?? null) ? $this->resource['grid'] : ['rows' => 8, 'cols' => 12],
            'data' => is_array($this->resource['data'] ?? null) ? $this->resource['data'] : [],
        ];
    }
}
