<?php

namespace App\Services;

use App\Models\SaasPlan;
use Illuminate\Support\Collection;

class SaasPlanService
{
    public function __construct(
        private readonly TenantFeatureService $features,
    ) {
    }

    public function list(array $filters): Collection
    {
        $this->features->ensureDefaultPlans();

        $query = SaasPlan::query()
            ->orderBy('sort_order')
            ->orderBy('id');

        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->get();
    }

    public function update(int $id, array $payload): SaasPlan
    {
        $this->features->ensureDefaultPlans();

        $plan = SaasPlan::query()->findOrFail($id);
        $plan->fill($payload)->save();

        return $plan->fresh();
    }
}
