<?php

namespace App\Http\Resources\Saas;

use App\Http\Resources\BaseJsonResource;
use App\Models\LandingLead;

class LandingLeadResource extends BaseJsonResource
{
    /**
     * @var LandingLead
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'source' => (string) $this->resource->source,
            'club_name' => (string) $this->resource->club_name,
            'city' => $this->resource->city,
            'pc_count' => (int) $this->resource->pc_count,
            'plan_code' => $this->resource->plan_code,
            'contact' => (string) $this->resource->contact,
            'message' => $this->resource->message,
            'locale' => $this->resource->locale,
            'status' => (string) $this->resource->status,
            'meta' => is_array($this->resource->meta) ? $this->resource->meta : [],
            'ip_address' => $this->resource->ip_address,
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
        ];
    }
}
