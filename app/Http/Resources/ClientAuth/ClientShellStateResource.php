<?php

namespace App\Http\Resources\ClientAuth;

use App\Http\Resources\BaseJsonResource;
use App\ValueObjects\ClientAuth\ClientShellStateResult;

class ClientShellStateResource extends BaseJsonResource
{
    /**
     * @var ClientShellStateResult
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'locked' => $this->resource->locked,
            'client' => $this->resource->client,
            'pc' => $this->resource->pc,
            'session' => [
                'id' => $this->resource->session['id'] ?? null,
                'status' => $this->resource->session['status'] ?? null,
                'started_at' => optional($this->resource->session['started_at'] ?? null)->toIso8601String(),
                'seconds_left' => $this->resource->session['seconds_left'] ?? 0,
                'from' => $this->resource->session['from'] ?? 'balance',
            ],
            'command' => $this->resource->command,
        ];
    }
}
