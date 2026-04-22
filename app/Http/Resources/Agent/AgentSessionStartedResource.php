<?php

namespace App\Http\Resources\Agent;

use App\Http\Resources\BaseJsonResource;
use App\Models\Session;

class AgentSessionStartedResource extends BaseJsonResource
{
    /**
     * @var Session
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'data' => [
                'session_id' => (int) $this->resource->id,
                'started_at' => $this->resource->started_at?->toIso8601String(),
            ],
        ];
    }
}
