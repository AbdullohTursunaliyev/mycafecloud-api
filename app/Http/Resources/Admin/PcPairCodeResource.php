<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;
use App\Models\PcPairCode;

class PcPairCodeResource extends BaseJsonResource
{
    /**
     * @var PcPairCode
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'code' => (string) $this->resource->code,
            'zone' => $this->resource->zone,
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
        ];
    }
}
