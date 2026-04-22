<?php

namespace App\Services;

use App\Models\Zone;
use Illuminate\Support\Collection;

class ZoneCatalogService
{
    public function list(int $tenantId, array $filters): Collection
    {
        $query = Zone::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_active')
            ->orderBy('name');

        if (($filters['active'] ?? null) !== null) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return $query->get();
    }

    public function create(int $tenantId, array $payload): Zone
    {
        return Zone::query()->create([
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'price_per_hour' => (int) $payload['price_per_hour'],
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
        ]);
    }

    public function update(int $tenantId, int $zoneId, array $payload): Zone
    {
        $zone = Zone::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($zoneId);

        $zone->fill($payload);
        $zone->save();

        return $zone;
    }

    public function toggle(int $tenantId, int $zoneId): Zone
    {
        $zone = Zone::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($zoneId);

        $zone->update([
            'is_active' => !$zone->is_active,
        ]);

        return $zone->fresh();
    }
}
