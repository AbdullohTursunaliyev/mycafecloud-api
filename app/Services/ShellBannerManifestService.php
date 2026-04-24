<?php

namespace App\Services;

use App\Models\Pc;
use App\Models\ShellBanner;
use Illuminate\Support\Collection;

class ShellBannerManifestService
{
    private const DEFAULT_TENANT_ID = 0;

    public function listForPc(int $tenantId, int $pcId): Collection
    {
        $pc = Pc::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($pcId);

        $now = now();

        $banners = ShellBanner::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('updated_at')
            ->get()
            ->filter(function (ShellBanner $banner) use ($pc, $now) {
                if ($banner->starts_at && $banner->starts_at->gt($now)) {
                    return false;
                }

                if ($banner->ends_at && $banner->ends_at->lt($now)) {
                    return false;
                }

                return match ($banner->target_scope) {
                    'zones' => in_array((int) $pc->zone_id, array_map('intval', (array) $banner->target_zone_ids), true),
                    'pcs' => in_array((int) $pc->id, array_map('intval', (array) $banner->target_pc_ids), true),
                    default => true,
                };
            })
            ->values();

        if ($banners->isNotEmpty()) {
            return $banners;
        }

        return $this->defaultBanners();
    }

    private function defaultBanners(): Collection
    {
        return ShellBanner::query()
            ->where('tenant_id', self::DEFAULT_TENANT_ID)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('updated_at')
            ->get()
            ->values();
    }
}
