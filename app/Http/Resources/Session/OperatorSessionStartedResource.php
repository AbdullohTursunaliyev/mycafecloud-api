<?php

namespace App\Http\Resources\Session;

use App\Http\Resources\BaseJsonResource;
use App\Models\Session;

class OperatorSessionStartedResource extends BaseJsonResource
{
    /**
     * @var Session
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'data' => [
                'id' => (int) $this->resource->id,
                'pc_id' => (int) $this->resource->pc_id,
                'client_id' => (int) $this->resource->client_id,
                'started_at' => $this->resource->started_at?->toIso8601String(),
            ],
        ];
    }
}
