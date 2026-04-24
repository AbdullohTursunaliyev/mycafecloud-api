<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;

class ClubVisualResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'name' => (string) $this->resource->name,
            'headline' => $this->resource->headline,
            'subheadline' => $this->resource->subheadline,
            'description_text' => $this->resource->description_text,
            'prompt_text' => $this->resource->prompt_text,
            'display_mode' => (string) $this->resource->display_mode,
            'screen_mode' => (string) $this->resource->screen_mode,
            'accent_color' => $this->resource->accent_color,
            'image_url' => $this->resource->image_url,
            'audio_url' => $this->resource->audio_url,
            'layout_spec' => is_array($this->resource->layout_spec) ? $this->resource->layout_spec : null,
            'visual_spec' => is_array($this->resource->visual_spec) ? $this->resource->visual_spec : null,
            'sort_order' => (int) $this->resource->sort_order,
            'is_active' => (bool) $this->resource->is_active,
            'updated_at' => optional($this->resource->updated_at)?->toIso8601String(),
            'created_at' => optional($this->resource->created_at)?->toIso8601String(),
        ];
    }
}
