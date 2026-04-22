<?php

namespace App\Http\Resources\Shift;

use App\Http\Resources\BaseJsonResource;

class CurrentShiftExpensesResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'data' => is_array($this->resource) ? $this->resource : [
                'shift' => null,
                'items' => [],
                'total' => 0,
            ],
        ];
    }
}
