<?php

namespace App\Services;

use App\Models\ShellBanner;
use Illuminate\Database\Eloquent\Collection;

class ShellBannerCatalogService
{
    public function list(int $tenantId): Collection
    {
        return ShellBanner::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function create(int $tenantId, array $payload): ShellBanner
    {
        return ShellBanner::query()->create([
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'headline' => $payload['headline'] ?? null,
            'subheadline' => $payload['subheadline'] ?? null,
            'body_text' => $payload['body_text'] ?? null,
            'cta_text' => $payload['cta_text'] ?? null,
            'prompt_text' => $payload['prompt_text'] ?? null,
            'image_url' => $payload['image_url'] ?? null,
            'logo_url' => $payload['logo_url'],
            'audio_url' => $payload['audio_url'] ?? null,
            'accent_color' => $payload['accent_color'] ?? null,
            'target_scope' => $payload['target_scope'],
            'target_zone_ids' => $payload['target_zone_ids'] ?? [],
            'target_pc_ids' => $payload['target_pc_ids'] ?? [],
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'] ?? null,
            'display_seconds' => (int) ($payload['display_seconds'] ?? 12),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
        ]);
    }

    public function update(int $tenantId, int $bannerId, array $payload): ShellBanner
    {
        $banner = $this->find($tenantId, $bannerId);
        $banner->fill($payload);
        $banner->save();

        return $banner->fresh();
    }

    public function toggle(int $tenantId, int $bannerId): ShellBanner
    {
        $banner = $this->find($tenantId, $bannerId);
        $banner->update([
            'is_active' => !$banner->is_active,
        ]);

        return $banner->fresh();
    }

    public function delete(int $tenantId, int $bannerId): void
    {
        $this->find($tenantId, $bannerId)->delete();
    }

    public function find(int $tenantId, int $bannerId): ShellBanner
    {
        return ShellBanner::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($bannerId);
    }
}
