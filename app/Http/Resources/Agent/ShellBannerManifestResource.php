<?php

namespace App\Http\Resources\Agent;

use App\Http\Resources\BaseJsonResource;

class ShellBannerManifestResource extends BaseJsonResource
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
            'image_url' => $this->resource->image_url,
            'logo_url' => $this->resource->logo_url,
            'accent_color' => $this->resource->accent_color,
            'display_seconds' => (int) $this->resource->display_seconds,
            'starts_at' => optional($this->resource->starts_at)?->toIso8601String(),
            'ends_at' => optional($this->resource->ends_at)?->toIso8601String(),
        ];
    }
}
