<?php

namespace App\Services;

use App\Models\Package;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PackageCatalogService
{
    public function paginate(int $tenantId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Package::query()
            ->where('tenant_id', $tenantId);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $operator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where('name', $operator, '%' . $search . '%');
        }

        if (($filters['active'] ?? null) !== null) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return $query
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(int $tenantId, array $payload): Package
    {
        return Package::query()->create([
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'duration_min' => (int) $payload['duration_min'],
            'price' => (int) $payload['price'],
            'zone' => $payload['zone'],
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
        ]);
    }

    public function update(int $tenantId, int $packageId, array $payload): Package
    {
        $package = Package::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($packageId);

        $package->fill($payload);
        $package->save();

        return $package;
    }

    public function toggle(int $tenantId, int $packageId): Package
    {
        $package = Package::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($packageId);

        $package->update([
            'is_active' => !$package->is_active,
        ]);

        return $package->fresh();
    }
}
