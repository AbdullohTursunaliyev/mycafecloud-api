<?php

namespace App\Http\Resources\Agent;

use App\Http\Resources\BaseJsonResource;
use App\ValueObjects\Agent\AgentPairResult;

class AgentPairResource extends BaseJsonResource
{
    /**
     * @var AgentPairResult
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'pc' => [
                'id' => $this->resource->pcId,
                'code' => $this->resource->pcCode,
                'zone' => $this->resource->zone,
            ],
            'device_token' => $this->resource->deviceToken,
            'device_token_expires_at' => $this->resource->deviceTokenExpiresAt?->toIso8601String(),
            'poll_interval_sec' => $this->resource->pollIntervalSec,
            'repair_mode' => $this->resource->repairMode,
        ];
    }
}
