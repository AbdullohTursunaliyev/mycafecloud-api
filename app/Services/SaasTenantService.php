<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SaasTenantService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Tenant::query()->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $operator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where('name', $operator, '%' . $search . '%');
        }

        return $query->paginate(20);
    }

    public function create(array $payload): Tenant
    {
        return Tenant::query()->create([
            'name' => $payload['name'],
            'status' => $payload['status'] ?? 'active',
        ]);
    }

    public function show(int $id): Tenant
    {
        return Tenant::query()
            ->withCount(['licenseKeys'])
            ->findOrFail($id);
    }

    public function update(int $id, array $payload): Tenant
    {
        $tenant = Tenant::query()->findOrFail($id);
        $tenant->fill($payload)->save();

        return $tenant->fresh();
    }
}
