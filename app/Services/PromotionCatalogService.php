<?php

namespace App\Services;

use App\Models\Promotion;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PromotionCatalogService
{
    public function __construct(
        private readonly PromotionEngine $engine,
    ) {
    }

    public function paginate(int $tenantId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Promotion::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_active')
            ->orderByDesc('priority')
            ->orderByDesc('id');

        if (($filters['active'] ?? null) !== null) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return $query->paginate($perPage);
    }

    public function create(int $tenantId, array $payload): Promotion
    {
        return Promotion::query()->create(array_merge($payload, [
            'tenant_id' => $tenantId,
        ]));
    }

    public function update(int $tenantId, int $promotionId, array $payload): Promotion
    {
        $promotion = Promotion::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($promotionId);

        $promotion->update($payload);

        return $promotion->fresh();
    }

    public function toggle(int $tenantId, int $promotionId): Promotion
    {
        $promotion = Promotion::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($promotionId);

        $promotion->update([
            'is_active' => !$promotion->is_active,
        ]);

        return $promotion->fresh();
    }

    public function activeForTopup(int $tenantId, string $paymentMethod, ?Carbon $now = null): array
    {
        $now = $now ?: now();
        $promotion = $this->engine->findActiveTopupPromotion($tenantId, $paymentMethod, $now);

        return [
            'promotion' => $promotion,
            'meta' => [
                'server_now' => $now->toDateTimeString(),
                'server_dow' => (int) $now->dayOfWeek,
                'server_time' => $now->format('H:i:s'),
                'payment_method' => $paymentMethod,
            ],
        ];
    }
}
