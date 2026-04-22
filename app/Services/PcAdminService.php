<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Pc;
use App\Models\PcHeartbeat;
use App\Models\Zone;
use Illuminate\Support\Collection;

class PcAdminService
{
    public function __construct(
        private readonly PcZoneResolver $zones,
    ) {
    }

    public function list(int $tenantId, array $filters): array
    {
        $query = Pc::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'zoneRel:id,tenant_id,name,price_per_hour',
                'activeSession.tariff',
                'activeSession.client:id,tenant_id,account_id,phone,login,balance',
            ])
            ->orderByRaw("COALESCE((SELECT name FROM zones WHERE zones.id = pcs.zone_id), pcs.zone) NULLS LAST")
            ->orderBy('code');

        if (!empty($filters['zone_id'])) {
            $query->where('zone_id', (int) $filters['zone_id']);
        } elseif (!empty($filters['zone'])) {
            $query->where('zone', (string) $filters['zone']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->where('code', 'ILIKE', '%' . $filters['search'] . '%');
        }

        $pcs = $query->get();
        if ($pcs->isEmpty()) {
            return [];
        }

        $pcIds = $pcs->pluck('id')->map(fn($id) => (int) $id)->all();

        $latestHeartbeatIds = PcHeartbeat::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('pc_id', $pcIds)
            ->selectRaw('MAX(id) as id')
            ->groupBy('pc_id');

        $heartbeats = PcHeartbeat::query()
            ->whereIn('id', $latestHeartbeatIds)
            ->get(['pc_id', 'received_at', 'metrics'])
            ->keyBy('pc_id');

        $now = now();
        $bookings = Booking::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->whereIn('pc_id', $pcIds)
            ->get(['id', 'pc_id', 'client_id', 'start_at', 'end_at'])
            ->keyBy('pc_id');

        return $pcs
            ->map(fn(Pc $pc) => $this->formatPc($pc, $heartbeats->get($pc->id), $bookings->get($pc->id)))
            ->values()
            ->all();
    }

    public function create(int $tenantId, array $payload): array
    {
        [$zoneId, $zoneName] = $this->resolveZonePair($tenantId, $payload['zone_id'] ?? null, $payload['zone'] ?? null);

        $pc = Pc::query()->create([
            'tenant_id' => $tenantId,
            'code' => $payload['code'],
            'zone_id' => $zoneId,
            'zone' => $zoneName,
            'ip_address' => $payload['ip_address'] ?? null,
            'status' => $payload['status'] ?? 'offline',
            'pos_x' => $payload['pos_x'] ?? null,
            'pos_y' => $payload['pos_y'] ?? null,
            'group' => $payload['group'] ?? null,
            'sort_order' => $payload['sort_order'] ?? 0,
            'notes' => $payload['notes'] ?? null,
            'is_hidden' => (bool) ($payload['is_hidden'] ?? false),
        ]);

        $pc->load('zoneRel:id,tenant_id,name,price_per_hour');

        return $this->formatPc($pc, null, null);
    }

    public function update(int $tenantId, int $pcId, array $payload): array
    {
        $pc = Pc::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($pcId);

        if (array_key_exists('zone_id', $payload) || array_key_exists('zone', $payload)) {
            $zoneId = array_key_exists('zone_id', $payload) ? ($payload['zone_id'] ?? null) : $pc->zone_id;
            $zoneName = array_key_exists('zone', $payload) ? ($payload['zone'] ?? null) : $pc->zone;

            if (array_key_exists('zone_id', $payload) && !array_key_exists('zone', $payload)) {
                $zoneName = null;
            }
            if (array_key_exists('zone', $payload) && !array_key_exists('zone_id', $payload)) {
                $zoneId = null;
            }

            [$payload['zone_id'], $payload['zone']] = $this->resolveZonePair(
                $tenantId,
                $zoneId,
                $zoneName,
            );
        }

        $pc->fill($payload);
        $pc->save();
        $pc->load('zoneRel:id,tenant_id,name,price_per_hour');

        return $this->formatPc($pc, null, null);
    }

    public function delete(int $tenantId, int $pcId): void
    {
        Pc::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($pcId)
            ->delete();
    }

    public function layoutBatchUpdate(int $tenantId, array $items): void
    {
        foreach ($items as $item) {
            $pc = Pc::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $item['id'])
                ->first();

            if (!$pc) {
                continue;
            }

            $zoneId = array_key_exists('zone_id', $item) ? ($item['zone_id'] ?? null) : $pc->zone_id;
            $zoneName = array_key_exists('zone', $item) ? ($item['zone'] ?? null) : $pc->zone;

            if (array_key_exists('zone_id', $item) && !array_key_exists('zone', $item)) {
                $zoneName = null;
            }
            if (array_key_exists('zone', $item) && !array_key_exists('zone_id', $item)) {
                $zoneId = null;
            }

            [$zoneId, $zoneName] = $this->resolveZonePair(
                $tenantId,
                $zoneId,
                $zoneName,
            );

            $pc->update([
                'pos_x' => array_key_exists('pos_x', $item) ? ($item['pos_x'] ?? null) : $pc->pos_x,
                'pos_y' => array_key_exists('pos_y', $item) ? ($item['pos_y'] ?? null) : $pc->pos_y,
                'sort_order' => array_key_exists('sort_order', $item) ? ($item['sort_order'] ?? 0) : $pc->sort_order,
                'zone_id' => $zoneId,
                'zone' => $zoneName,
                'group' => array_key_exists('group', $item) ? ($item['group'] ?? null) : $pc->group,
            ]);
        }
    }

    private function formatPc(Pc $pc, ?PcHeartbeat $heartbeat, ?Booking $booking): array
    {
        $resolvedZone = $this->zones->resolveNameAndRate($pc);
        $activeSession = $pc->activeSession;
        $metrics = is_array($heartbeat?->metrics) ? $heartbeat->metrics : [];
        $hasActiveSession = $activeSession !== null;

        $status = $pc->status;
        if ($booking && !$hasActiveSession) {
            $status = 'reserved';
        } elseif ($hasActiveSession) {
            $status = 'busy';
        }

        return [
            'id' => $pc->id,
            'code' => $pc->code,
            'zone_id' => $pc->zone_id,
            'zone' => $resolvedZone['zone_name'],
            'zone_price_per_hour' => $resolvedZone['rate_per_hour'] ?: null,
            'status' => $status,
            'ip_address' => $pc->ip_address,
            'last_seen_at' => optional($pc->last_seen_at)?->toIso8601String(),
            'telemetry' => [
                'received_at' => optional($heartbeat?->received_at)?->toIso8601String(),
                'cpu_name' => $metrics['cpu_name'] ?? null,
                'ram_total_mb' => isset($metrics['ram_total_mb']) ? (int) $metrics['ram_total_mb'] : null,
                'gpu_name' => $metrics['gpu_name'] ?? null,
                'mac_address' => $metrics['mac_address'] ?? null,
                'ip_address' => $metrics['ip_address'] ?? null,
                'disks' => isset($metrics['disks']) && is_array($metrics['disks']) ? array_values($metrics['disks']) : [],
            ],
            'client_balance' => $activeSession?->client?->balance,
            'active_session' => $activeSession ? [
                'id' => $activeSession->id,
                'started_at' => $activeSession->started_at?->toIso8601String(),
                'tariff' => $activeSession->tariff ? [
                    'id' => $activeSession->tariff->id,
                    'name' => $activeSession->tariff->name,
                    'price_per_hour' => $activeSession->tariff->price_per_hour,
                ] : null,
                'client' => $activeSession->client ? [
                    'id' => $activeSession->client->id,
                    'account_id' => $activeSession->client->account_id,
                    'phone' => $activeSession->client->phone,
                    'login' => $activeSession->client->login,
                    'balance' => $activeSession->client->balance,
                ] : null,
            ] : null,
            'current_booking' => $booking ? [
                'id' => (int) $booking->id,
                'client_id' => (int) $booking->client_id,
                'start_at' => optional($booking->start_at)->toIso8601String(),
                'end_at' => optional($booking->end_at)->toIso8601String(),
            ] : null,
        ];
    }

    private function resolveZonePair(int $tenantId, mixed $zoneId, mixed $zoneName): array
    {
        $resolvedZoneId = $zoneId === null ? null : (int) $zoneId;
        $resolvedZoneName = is_string($zoneName) ? trim($zoneName) : null;
        $resolvedZoneName = $resolvedZoneName === '' ? null : $resolvedZoneName;

        if (!$resolvedZoneId && $resolvedZoneName) {
            $resolvedZoneId = Zone::query()
                ->where('tenant_id', $tenantId)
                ->where('name', $resolvedZoneName)
                ->value('id');
        }

        if ($resolvedZoneId && !$resolvedZoneName) {
            $resolvedZoneName = Zone::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $resolvedZoneId)
                ->value('name');
        }

        return [$resolvedZoneId, $resolvedZoneName];
    }
}
