<?php

namespace App\Http\Resources\OwnerMobile;

use App\Http\Resources\BaseJsonResource;

class OwnerMobileLoginResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
