<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SaasTenantService
{
    public function __construct(
        private readonly TenantFeatureService $features,
    ) {
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Tenant::query()
            ->with('saasPlan')
            ->withCount(['licenseKeys', 'pcs'])
            ->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (!empty($filters['plan_code'])) {
            $query->whereHas('saasPlan', function ($builder) use ($filters): void {
                $builder->where('code', (string) $filters['plan_code']);
            });
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
            'saas_plan_id' => $payload['saas_plan_id'] ?? $this->features->defaultPlanId(),
        ]);
    }

    public function show(int $id): Tenant
    {
        return Tenant::query()
            ->with(['saasPlan'])
            ->withCount(['licenseKeys', 'pcs'])
            ->findOrFail($id);
    }

    public function update(int $id, array $payload): Tenant
    {
        $tenant = Tenant::query()->findOrFail($id);
        $tenant->fill($payload)->save();

        return $tenant->fresh(['saasPlan'])->loadCount(['licenseKeys', 'pcs']);
    }
}
