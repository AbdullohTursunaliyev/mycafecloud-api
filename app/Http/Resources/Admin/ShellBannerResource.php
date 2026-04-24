<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;

class ShellBannerResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'name' => (string) $this->resource->name,
            'headline' => $this->resource->headline,
            'subheadline' => $this->resource->subheadline,
            'body_text' => $this->resource->body_text,
            'cta_text' => $this->resource->cta_text,
            'prompt_text' => $this->resource->prompt_text,
            'image_url' => $this->resource->image_url,
            'logo_url' => $this->resource->logo_url,
            'audio_url' => $this->resource->audio_url,
            'accent_color' => $this->resource->accent_color,
            'target_scope' => (string) $this->resource->target_scope,
            'target_zone_ids' => array_values(array_map('intval', is_array($this->resource->target_zone_ids) ? $this->resource->target_zone_ids : [])),
            'target_pc_ids' => array_values(array_map('intval', is_array($this->resource->target_pc_ids) ? $this->resource->target_pc_ids : [])),
            'starts_at' => optional($this->resource->starts_at)?->toIso8601String(),
            'ends_at' => optional($this->resource->ends_at)?->toIso8601String(),
            'display_seconds' => (int) $this->resource->display_seconds,
            'sort_order' => (int) $this->resource->sort_order,
            'is_active' => (bool) $this->resource->is_active,
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
        ];
    }
}
