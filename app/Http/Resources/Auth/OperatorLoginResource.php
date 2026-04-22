<?php

namespace App\Http\Resources\Auth;

use App\Http\Resources\BaseJsonResource;

class OperatorLoginResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
