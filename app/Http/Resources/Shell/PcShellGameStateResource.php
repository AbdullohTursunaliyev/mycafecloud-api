<?php

namespace App\Http\Resources\Shell;

use App\Http\Resources\BaseJsonResource;
use App\Models\PcShellGame;

class PcShellGameStateResource extends BaseJsonResource
{
    /**
     * @var PcShellGame
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'tenant_id' => (int) $this->resource->tenant_id,
            'pc_id' => (int) $this->resource->pc_id,
            'shell_game_id' => (int) $this->resource->shell_game_id,
            'is_installed' => (bool) $this->resource->is_installed,
            'version' => $this->resource->version,
            'last_seen_at' => optional($this->resource->last_seen_at)->toIso8601String(),
            'last_error' => $this->resource->last_error,
        ];
    }
}
