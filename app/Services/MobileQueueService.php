<?php

namespace App\Services;

use App\Models\MobileSmartQueue;
use App\Models\Pc;
use App\Models\PcBooking;
use App\Models\Session;
use Carbon\Carbon;

class MobileQueueService
{
    public function zoneKeyForPc(Pc $pc): string
    {
        $zoneId = (int) ($pc->zone_id ?? 0);
        if ($zoneId > 0) {
            return 'id:' . $zoneId;
        }

        $name = trim((string) ($pc->zoneRel?->name ?? $pc->zone ?? 'Default'));
        if ($name === '') {
            $name = 'Default';
        }

        return 'name:' . strtolower($name);
    }

    public function normalizeZoneKey(?string $zoneKey): ?string
    {
        $raw = trim((string) $zoneKey);

        return $raw === '' ? null : strtolower($raw);
    }

    public function zoneSnapshot(int $tenantId, ?string $zoneKey): array
    {
        $now = now();
        $pcs = Pc::query()
            ->where('tenant_id', $tenantId)
            ->with(['zoneRel:id,name'])
            ->get(['id', 'zone_id', 'zone', 'code']);

        $pcMeta = [];
        foreach ($pcs as $pc) {
            $key = $this->zoneKeyForPc($pc);
            if ($zoneKey !== null && $key !== $zoneKey) {
                continue;
            }

            $name = trim((string) ($pc->zoneRel?->name ?? $pc->zone ?? 'Default'));
            if ($name === '') {
                $name = 'Default';
            }

            $pcMeta[(int) $pc->id] = [
                'zone_key' => $key,
                'zone_name' => $name,
            ];
        }

        if (empty($pcMeta)) {
            return [
                'zone_key' => $zoneKey,
                'zone_name' => '',
                'free_now' => 0,
                'eta_min' => 0,
            ];
        }

        $zoneName = array_values($pcMeta)[0]['zone_name'] ?? '';
        $pcIds = array_keys($pcMeta);

        $busyRows = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('pc_id', $pcIds)
            ->get(['pc_id', 'started_at']);
        $bookingRows = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('pc_id', $pcIds)
            ->where('reserved_until', '>', $now)
            ->get(['pc_id', 'reserved_until']);

        $busySet = [];
        foreach ($busyRows as $row) {
            $busySet[(int) $row->pc_id] = $row;
        }

        $bookingSet = [];
        foreach ($bookingRows as $row) {
            $bookingSet[(int) $row->pc_id] = $row;
        }

        $freeNow = 0;
        foreach ($pcIds as $pcId) {
            if (isset($busySet[$pcId]) || isset($bookingSet[$pcId])) {
                continue;
            }
            $freeNow++;
        }

        if ($freeNow > 0) {
            return [
                'zone_key' => $zoneKey,
                'zone_name' => (string) $zoneName,
                'free_now' => $freeNow,
                'eta_min' => 0,
            ];
        }

        $avgDuration = 120;
        $endedRows = Session::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('pc_id', $pcIds)
            ->whereNotNull('started_at')
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', $now->copy()->subDays(14))
            ->get(['started_at', 'ended_at']);

        if ($endedRows->isNotEmpty()) {
            $sum = 0;
            $count = 0;
            foreach ($endedRows as $row) {
                $start = Carbon::parse((string) $row->started_at);
                $end = Carbon::parse((string) $row->ended_at);
                if ($end->lessThanOrEqualTo($start)) {
                    continue;
                }

                $sum += max(1, (int) $start->diffInMinutes($end));
                $count++;
            }

            if ($count > 0) {
                $avgDuration = max(30, (int) round($sum / $count));
            }
        }

        $etas = [];
        foreach ($busyRows as $row) {
            $startedAt = $row->started_at ? Carbon::parse((string) $row->started_at) : null;
            if (!$startedAt) {
                continue;
            }

            $elapsed = (int) $startedAt->diffInMinutes($now);
            $etas[] = max(5, $avgDuration - $elapsed);
        }

        foreach ($bookingRows as $row) {
            $remainSec = $now->diffInSeconds(Carbon::parse((string) $row->reserved_until), false);
            $etas[] = max(1, (int) ceil($remainSec / 60));
        }

        $etaMin = !empty($etas)
            ? max(1, min($etas))
            : max(5, (int) round($avgDuration / 2));

        return [
            'zone_key' => $zoneKey,
            'zone_name' => (string) $zoneName,
            'free_now' => 0,
            'eta_min' => $etaMin,
        ];
    }

    public function listForClient(int $tenantId, int $clientId): array
    {
        $now = now();
        $rows = MobileSmartQueue::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->whereIn('status', ['waiting', 'notified'])
            ->orderBy('id')
            ->get(['id', 'zone_key', 'notify_on_free', 'status', 'notified_at', 'created_at', 'updated_at']);

        $items = [];
        $notifications = [];

        foreach ($rows as $row) {
            $zoneKey = $this->normalizeZoneKey($row->zone_key);
            $positionQuery = MobileSmartQueue::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['waiting', 'notified'])
                ->where('id', '<=', (int) $row->id);

            if ($zoneKey === null) {
                $positionQuery->whereNull('zone_key');
            } else {
                $positionQuery->where('zone_key', $zoneKey);
            }

            $position = (int) $positionQuery->count();
            $snapshot = $this->zoneSnapshot($tenantId, $zoneKey);
            $readyNow = (int) ($snapshot['free_now'] ?? 0) > 0;
            $notifyOnFree = (bool) $row->notify_on_free;
            $notifiedAt = $row->notified_at;
            $needsNotify = $notifyOnFree && $readyNow && $notifiedAt === null;

            if ($needsNotify) {
                $row->update([
                    'status' => 'notified',
                    'notified_at' => $now,
                    'updated_at' => $now,
                ]);
                $notifiedAt = $now;
                $notifications[] = [
                    'id' => 'smart-queue-' . (int) $row->id . '-' . $now->timestamp,
                    'type' => 'smart_queue_ready',
                    'queue_id' => (int) $row->id,
                    'zone_key' => $zoneKey,
                    'zone_name' => (string) ($snapshot['zone_name'] ?? ''),
                    'message' => 'Smart queue: free PC is available now.',
                ];
            }

            $items[] = [
                'id' => (int) $row->id,
                'zone_key' => $zoneKey,
                'zone_name' => (string) ($snapshot['zone_name'] ?? ''),
                'position' => max(1, $position),
                'status' => $needsNotify ? 'notified' : (string) $row->status,
                'notify_on_free' => $notifyOnFree,
                'ready_now' => $readyNow,
                'free_now' => (int) ($snapshot['free_now'] ?? 0),
                'eta_min' => (int) ($snapshot['eta_min'] ?? 0),
                'created_at' => optional($row->created_at)->toIso8601String(),
                'updated_at' => optional($row->updated_at)->toIso8601String(),
                'notified_at' => optional($notifiedAt)->toIso8601String(),
            ];
        }

        return [
            'count' => count($items),
            'items' => $items,
            'notifications' => $notifications,
        ];
    }

    public function join(int $tenantId, int $clientId, ?string $zoneKey, bool $notifyOnFree): array
    {
        $zoneKey = $this->normalizeZoneKey($zoneKey);
        $now = now();

        $existingQuery = MobileSmartQueue::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->whereIn('status', ['waiting', 'notified']);

        if ($zoneKey === null) {
            $existingQuery->whereNull('zone_key');
        } else {
            $existingQuery->where('zone_key', $zoneKey);
        }

        $existing = $existingQuery->orderByDesc('id')->first();

        if ($existing) {
            $existing->update([
                'status' => 'waiting',
                'notify_on_free' => $notifyOnFree,
                'notified_at' => null,
                'updated_at' => $now,
            ]);
            $rowId = (int) $existing->id;
        } else {
            $existing = MobileSmartQueue::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $clientId,
                'zone_key' => $zoneKey,
                'notify_on_free' => $notifyOnFree,
                'status' => 'waiting',
                'notified_at' => null,
            ]);
            $rowId = (int) $existing->id;
        }

        $positionQuery = MobileSmartQueue::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['waiting', 'notified'])
            ->where('id', '<=', $rowId);

        if ($zoneKey === null) {
            $positionQuery->whereNull('zone_key');
        } else {
            $positionQuery->where('zone_key', $zoneKey);
        }

        $position = (int) $positionQuery->count();
        $snapshot = $this->zoneSnapshot($tenantId, $zoneKey);

        return [
            'ok' => true,
            'queue' => [
                'id' => $rowId,
                'zone_key' => $zoneKey,
                'zone_name' => (string) ($snapshot['zone_name'] ?? ''),
                'position' => max(1, $position),
                'status' => 'waiting',
                'notify_on_free' => $notifyOnFree,
                'ready_now' => (int) ($snapshot['free_now'] ?? 0) > 0,
                'free_now' => (int) ($snapshot['free_now'] ?? 0),
                'eta_min' => (int) ($snapshot['eta_min'] ?? 0),
            ],
        ];
    }

    public function cancel(int $tenantId, int $clientId, int $id): array
    {
        $affected = MobileSmartQueue::query()
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->whereIn('status', ['waiting', 'notified'])
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

        return [
            'ok' => true,
            'cancelled' => $affected > 0,
        ];
    }
}
