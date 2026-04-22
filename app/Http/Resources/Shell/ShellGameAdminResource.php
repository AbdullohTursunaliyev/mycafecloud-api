<?php

namespace App\Http\Resources\Shell;

use App\Http\Resources\BaseJsonResource;

class ShellGameAdminResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
