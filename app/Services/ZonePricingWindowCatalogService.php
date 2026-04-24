<?php

namespace App\Services;

use App\Models\Zone;
use App\Models\ZonePricingWindow;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ZonePricingWindowCatalogService
{
    public function list(int $tenantId, int $zoneId, array $filters): Collection
    {
        $this->resolveZone($tenantId, $zoneId);

        $query = ZonePricingWindow::query()
            ->where('tenant_id', $tenantId)
            ->where('zone_id', $zoneId)
            ->orderByDesc('is_active')
            ->orderBy('starts_on')
            ->orderBy('ends_on')
            ->orderBy('starts_at')
            ->orderBy('id');

        if (($filters['active'] ?? null) !== null) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return $query->get();
    }

    public function create(int $tenantId, int $zoneId, array $payload): ZonePricingWindow
    {
        $this->resolveZone($tenantId, $zoneId);
        $this->assertDateRange($payload['starts_on'] ?? null, $payload['ends_on'] ?? null);

        return ZonePricingWindow::query()->create([
            'tenant_id' => $tenantId,
            'zone_id' => $zoneId,
            'name' => $payload['name'] ?? null,
            'starts_at' => $payload['starts_at'],
            'ends_at' => $payload['ends_at'],
            'starts_on' => $payload['starts_on'] ?? null,
            'ends_on' => $payload['ends_on'] ?? null,
            'weekdays' => $payload['weekdays'] ?? [],
            'price_per_hour' => (int) $payload['price_per_hour'],
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
        ]);
    }

    public function update(int $tenantId, int $zoneId, int $windowId, array $payload): ZonePricingWindow
    {
        $window = $this->resolveWindow($tenantId, $zoneId, $windowId);
        $this->assertDateRange(
            array_key_exists('starts_on', $payload) ? $payload['starts_on'] : $window->starts_on?->toDateString(),
            array_key_exists('ends_on', $payload) ? $payload['ends_on'] : $window->ends_on?->toDateString(),
        );
        $window->fill($payload);
        $window->save();

        return $window->fresh();
    }

    public function toggle(int $tenantId, int $zoneId, int $windowId): ZonePricingWindow
    {
        $window = $this->resolveWindow($tenantId, $zoneId, $windowId);
        $window->update([
            'is_active' => !$window->is_active,
        ]);

        return $window->fresh();
    }

    public function delete(int $tenantId, int $zoneId, int $windowId): void
    {
        $this->resolveWindow($tenantId, $zoneId, $windowId)->delete();
    }

    private function resolveZone(int $tenantId, int $zoneId): Zone
    {
        return Zone::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($zoneId);
    }

    private function resolveWindow(int $tenantId, int $zoneId, int $windowId): ZonePricingWindow
    {
        $this->resolveZone($tenantId, $zoneId);

        return ZonePricingWindow::query()
            ->where('tenant_id', $tenantId)
            ->where('zone_id', $zoneId)
            ->findOrFail($windowId);
    }

    private function assertDateRange(?string $startsOn, ?string $endsOn): void
    {
        if (!$startsOn || !$endsOn) {
            return;
        }

        if ($startsOn > $endsOn) {
            throw ValidationException::withMessages([
                'ends_on' => 'End date must be after or equal to start date.',
            ]);
        }
    }
}
