<?php

namespace App\Http\Resources\Shift;

use App\Http\Resources\BaseJsonResource;

class ShiftHistoryShowResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'data' => is_array($this->resource) ? $this->resource : [],
        ];
    }
}
