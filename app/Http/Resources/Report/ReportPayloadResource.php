<?php

namespace App\Http\Resources\Report;

use App\Http\Resources\BaseJsonResource;

class ReportPayloadResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'data' => is_array($this->resource) ? $this->resource : [],
        ];
    }
}
