<?php

namespace App\Http\Resources\Cp;

use App\Http\Resources\BaseJsonResource;

class CpLoginResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
