<?php

namespace App\Http\Resources\Agent;

use App\Http\Resources\BaseJsonResource;

class AgentPollResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        $data = [
            'commands' => is_array($this->resource['commands'] ?? null)
                ? $this->resource['commands']
                : [],
        ];

        if (array_key_exists('device_token', $this->resource)) {
            $data['device_token'] = $this->resource['device_token'];
            $data['device_token_expires_at'] = $this->resource['device_token_expires_at'] ?? null;
        }

        return $data;
    }
}
