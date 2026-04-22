<?php

namespace App\Http\Resources\Session;

use App\Http\Resources\BaseJsonResource;

class ActiveSessionResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
