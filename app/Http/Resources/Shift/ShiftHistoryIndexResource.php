<?php

namespace App\Http\Resources\Shift;

use App\Http\Resources\BaseJsonResource;

class ShiftHistoryIndexResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'data' => [
                'items' => $this->resource['items'] ?? [],
                'pagination' => $this->resource['pagination'] ?? [],
                'summary' => $this->resource['summary'] ?? [],
            ],
        ];
    }
}
