<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;

class TenantJoinCodeResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'join_code' => (string) ($this->resource['join_code'] ?? ''),
            'expires_at' => $this->resource['expires_at'] ?? null,
            'active' => (bool) ($this->resource['active'] ?? false),
        ];
    }
}
