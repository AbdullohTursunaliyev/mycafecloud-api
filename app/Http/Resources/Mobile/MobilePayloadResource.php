<?php

namespace App\Http\Resources\Mobile;

use App\Http\Resources\BaseJsonResource;

class MobilePayloadResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
