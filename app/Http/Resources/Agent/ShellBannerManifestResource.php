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
            'image_url' => $this->absoluteUrl($this->resource->image_url, $request),
            'logo_url' => $this->absoluteUrl($this->resource->logo_url, $request),
            'accent_color' => $this->resource->accent_color,
            'display_seconds' => (int) $this->resource->display_seconds,
            'starts_at' => optional($this->resource->starts_at)?->toIso8601String(),
            'ends_at' => optional($this->resource->ends_at)?->toIso8601String(),
        ];
    }

    private function absoluteUrl(?string $url, $request): ?string
    {
        if (!$url) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/') . '/' . ltrim($url, '/');
    }
}
