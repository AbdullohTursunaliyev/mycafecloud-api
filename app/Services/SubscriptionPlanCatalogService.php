<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SubscriptionPlanCatalogService
{
    public function paginate(int $tenantId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = SubscriptionPlan::query()
            ->where('tenant_id', $tenantId)
            ->with(['zone:id,name'])
            ->orderByDesc('is_active')
            ->orderByDesc('id');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        if (($filters['zone_id'] ?? null) !== null) {
            $query->where('zone_id', (int) $filters['zone_id']);
        }

        if (($filters['active'] ?? null) !== null) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return $query->paginate($perPage);
    }

    public function create(int $tenantId, array $payload): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()->create([
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'zone_id' => (int) $payload['zone_id'],
            'duration_days' => (int) $payload['duration_days'],
            'price' => (int) $payload['price'],
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);

        return $plan->load(['zone:id,name']);
    }

    public function update(int $tenantId, int $planId, array $payload): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($planId);

        $plan->fill($payload);
        $plan->save();

        return $plan->load(['zone:id,name']);
    }

    public function toggle(int $tenantId, int $planId): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($planId);

        $plan->update([
            'is_active' => !$plan->is_active,
        ]);

        return $plan->fresh()->load(['zone:id,name']);
    }
}
