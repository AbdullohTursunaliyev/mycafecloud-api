<?php

namespace App\Http\Resources\Agent;

use App\Http\Resources\BaseJsonResource;

class AgentSettingsResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        $data = [
            'settings' => is_array($this->resource) ? $this->resource : [],
        ];

        if (is_array($this->resource) && isset($this->resource['settings'])) {
            $data['settings'] = $this->resource['settings'];
        }

        if (is_array($this->resource) && array_key_exists('device_token', $this->resource)) {
            $data['device_token'] = $this->resource['device_token'];
            $data['device_token_expires_at'] = $this->resource['device_token_expires_at'] ?? null;
        }

        return $data;
    }
}
