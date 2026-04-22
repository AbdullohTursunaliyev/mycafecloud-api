<?php

namespace App\Http\Resources\Shift;

use App\Http\Resources\BaseJsonResource;

class ShiftReportResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'data' => is_array($this->resource) ? $this->resource : [],
        ];
    }
}
