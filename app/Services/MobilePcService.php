<?php

namespace App\Services;

use App\Models\Pc;
use App\Models\PcBooking;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;

class MobilePcService
{
    public function __construct(
        private readonly MobileQueueService $queues,
    ) {
    }

    public function catalog(int $tenantId, int $clientId): array
    {
        $now = now();
        $pcs = Pc::query()
            ->where('tenant_id', $tenantId)
            ->with(['zoneRel:id,name,price_per_hour'])
            ->orderByRaw('COALESCE(sort_order, 0)')
            ->orderBy('code')
            ->get(['id', 'code', 'zone_id', 'zone', 'status']);

        $bookings = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('reserved_until', '>', $now)
            ->get()
            ->keyBy('pc_id');

        $busyPcIds = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->pluck('pc_id')
            ->flip();

        $items = [];
        foreach ($pcs as $pc) {
            $booking = $bookings[$pc->id] ?? null;
            $isBusy = $busyPcIds->has($pc->id);
            $isBooked = !$isBusy && $booking !== null;
            $status = $isBusy ? 'busy' : ($isBooked ? 'booked' : 'free');
            $mine = $booking ? ((int) $booking->client_id === $clientId) : false;
            $zoneName = $pc->zoneRel?->name ?? $pc->zone ?? 'Default';
            $zonePrice = (int) ($pc->zoneRel?->price_per_hour ?? 0);

            $items[] = [
                'id' => (int) $pc->id,
                'name' => (string) ($pc->code ?? ('PC #' . $pc->id)),
                'code' => (string) ($pc->code ?? ''),
                'status' => $status,
                'zone' => [
                    'id' => (int) ($pc->zone_id ?? 0),
                    'name' => (string) $zoneName,
                    'price_per_hour' => $zonePrice,
                ],
                'booking' => $booking ? [
                    'client_id' => (int) $booking->client_id,
                    'reserved_from' => optional($booking->reserved_from)->toIso8601String(),
                    'reserved_until' => optional($booking->reserved_until)->toIso8601String(),
                    'is_mine' => $mine,
                ] : null,
                'can_book' => !$isBusy && (!$booking || $mine),
                'can_unbook' => !$isBusy && $booking && $mine,
            ];
        }

        return ['pcs' => $items];
    }

    public function book(int $tenantId, int $clientId, int $pcId, ?string $startAtRaw, ?int $holdMinutes): array
    {
        $startAt = $startAtRaw ? Carbon::parse($startAtRaw) : now();
        if ($startAt->lt(now()->subMinute())) {
            throw new HttpResponseException(response()->json(['message' => 'Start time cannot be in the past'], 422));
        }

        $holdMinutes = (int) ($holdMinutes ?? 60);
        $pc = Pc::query()->where('tenant_id', $tenantId)->findOrFail($pcId);

        if (!$this->pcCanBeBooked($tenantId, $clientId, (int) $pc->id)) {
            throw new HttpResponseException(response()->json(['message' => 'PC is already booked by another client or busy now'], 422));
        }

        $until = $startAt->copy()->addMinutes($holdMinutes);
        PcBooking::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'pc_id' => (int) $pc->id],
            ['client_id' => $clientId, 'reserved_from' => $startAt, 'reserved_until' => $until],
        );

        return [
            'ok' => true,
            'reserved_from' => $startAt->toIso8601String(),
            'reserved_until' => $until->toIso8601String(),
        ];
    }

    public function partyBook(int $tenantId, int $clientId, array $pcIds, ?string $startAtRaw, ?int $holdMinutes): array
    {
        $pcIds = collect($pcIds)
            ->map(static fn($value) => (int) $value)
            ->filter(static fn($value) => $value > 0)
            ->unique()
            ->values();

        if ($pcIds->count() < 2) {
            throw new HttpResponseException(response()->json(['message' => 'Select at least 2 PCs'], 422));
        }

        $startAt = $startAtRaw ? Carbon::parse($startAtRaw) : now();
        if ($startAt->lt(now()->subMinute())) {
            throw new HttpResponseException(response()->json(['message' => 'Start time cannot be in the past'], 422));
        }

        $holdMinutes = (int) ($holdMinutes ?? 60);
        $until = $startAt->copy()->addMinutes($holdMinutes);

        $pcs = Pc::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $pcIds->all())
            ->get(['id', 'zone_id', 'zone', 'code']);

        if ($pcs->count() !== $pcIds->count()) {
            throw new HttpResponseException(response()->json(['message' => 'Some PCs were not found'], 404));
        }

        $zoneKeys = $pcs->map(function ($pc) {
            if ((int) ($pc->zone_id ?? 0) > 0) {
                return 'id:' . (int) $pc->zone_id;
            }

            $name = mb_strtolower(trim((string) ($pc->zone ?? 'default')));
            return 'name:' . ($name !== '' ? $name : 'default');
        })->unique()->values();

        if ($zoneKeys->count() > 1) {
            throw new HttpResponseException(response()->json(['message' => 'Party booking requires PCs from the same zone'], 422));
        }

        $busyIds = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('pc_id', $pcIds->all())
            ->pluck('pc_id')
            ->map(static fn($value) => (int) $value)
            ->all();

        if (!empty($busyIds)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Some selected PCs are busy now',
                'busy_pc_ids' => array_values($busyIds),
            ], 422));
        }

        $existing = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('pc_id', $pcIds->all())
            ->where('reserved_until', '>', now())
            ->get()
            ->keyBy('pc_id');

        $blocked = [];
        foreach ($pcIds as $pcId) {
            $current = $existing->get((int) $pcId);
            if ($current && (int) $current->client_id !== $clientId) {
                $blocked[] = (int) $pcId;
            }
        }

        if (!empty($blocked)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Some selected PCs are already booked',
                'booked_pc_ids' => array_values($blocked),
            ], 422));
        }

        DB::transaction(function () use ($tenantId, $clientId, $pcIds, $startAt, $until) {
            foreach ($pcIds as $pcId) {
                PcBooking::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'pc_id' => (int) $pcId],
                    ['client_id' => $clientId, 'reserved_from' => $startAt, 'reserved_until' => $until],
                );
            }
        });

        return [
            'ok' => true,
            'reserved_count' => $pcIds->count(),
            'pc_ids' => $pcIds->all(),
            'reserved_from' => $startAt->toIso8601String(),
            'reserved_until' => $until->toIso8601String(),
        ];
    }

    public function smartSeat(int $tenantId, int $clientId, ?string $zoneKey, int $arriveIn, int $limit): array
    {
        $now = now();
        $zoneKey = $this->queues->normalizeZoneKey($zoneKey);
        $arriveIn = max(1, min(90, $arriveIn));
        $limit = max(1, min(5, $limit));
        $arriveUntil = $now->copy()->addMinutes($arriveIn);

        $pcs = Pc::query()
            ->where('tenant_id', $tenantId)
            ->with(['zoneRel:id,name'])
            ->orderByRaw('COALESCE(sort_order, 0)')
            ->orderBy('code')
            ->get(['id', 'code', 'zone_id', 'zone']);

        $bookings = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('reserved_until', '>', $now)
            ->get()
            ->keyBy('pc_id');

        $busyPcIds = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->pluck('pc_id')
            ->map(static fn($value) => (int) $value)
            ->flip();

        $freeNow = [];
        $soon = [];

        foreach ($pcs as $pc) {
            $pcId = (int) $pc->id;
            $pcZoneKey = $this->queues->zoneKeyForPc($pc);
            $isPreferredZone = ($zoneKey !== null && $zoneKey === $pcZoneKey);

            if ($busyPcIds->has($pcId)) {
                continue;
            }

            $booking = $bookings->get($pcId);
            $pcName = trim((string) ($pc->code ?? ''));
            if ($pcName === '') {
                $pcName = 'PC #' . $pcId;
            }

            $zoneName = trim((string) ($pc->zoneRel?->name ?? $pc->zone ?? 'Default'));
            if ($zoneName === '') {
                $zoneName = 'Default';
            }

            if (!$booking) {
                $freeNow[] = [
                    'pc_id' => $pcId,
                    'pc_name' => $pcName,
                    'zone_key' => $pcZoneKey,
                    'zone_name' => $zoneName,
                    'status' => 'free',
                    'eta_minutes' => 0,
                    'hold_available' => true,
                    'is_mine' => false,
                    'is_preferred_zone' => $isPreferredZone,
                ];
                continue;
            }

            $etaSec = max(0, $now->diffInSeconds($booking->reserved_until, false));
            $etaMin = (int) ceil($etaSec / 60);
            if ($etaMin <= $arriveIn) {
                $soon[] = [
                    'pc_id' => $pcId,
                    'pc_name' => $pcName,
                    'zone_key' => $pcZoneKey,
                    'zone_name' => $zoneName,
                    'status' => 'booked',
                    'eta_minutes' => $etaMin,
                    'hold_available' => false,
                    'is_mine' => ((int) $booking->client_id === $clientId),
                    'reserved_until' => optional($booking->reserved_until)->toIso8601String(),
                    'is_preferred_zone' => $isPreferredZone,
                ];
            }
        }

        $freeNowCollection = collect($freeNow);
        if ($zoneKey !== null) {
            $freeNowCollection = $freeNowCollection
                ->sortByDesc(static fn($row) => !empty($row['is_preferred_zone']) ? 1 : 0)
                ->values();
        }

        $soonCollection = collect($soon);
        if ($zoneKey !== null) {
            $soonCollection = $soonCollection
                ->sortBy(static function ($row) {
                    $preferredOrder = !empty($row['is_preferred_zone']) ? 0 : 1;
                    $etaOrder = (int) ($row['eta_minutes'] ?? 9999);
                    return sprintf('%d-%05d', $preferredOrder, $etaOrder);
                })
                ->values();
        } else {
            $soonCollection = $soonCollection->sortBy('eta_minutes')->values();
        }

        $picked = $freeNowCollection->take($limit)->values();
        if ($picked->count() < $limit) {
            $need = $limit - $picked->count();
            $picked = $picked->concat($soonCollection->take($need)->values())->values();
        }

        return [
            'ok' => true,
            'meta' => [
                'arrive_in' => $arriveIn,
                'limit' => $limit,
                'zone_key' => $zoneKey,
                'server_now' => $now->toIso8601String(),
                'arrive_until' => $arriveUntil->toIso8601String(),
            ],
            'items' => $picked->all(),
        ];
    }

    public function smartSeatHold(int $tenantId, int $clientId, int $pcId, ?int $holdMinutes): array
    {
        $holdMinutes = (int) ($holdMinutes ?? 15);
        $startAt = now();
        $until = $startAt->copy()->addMinutes($holdMinutes);

        $pc = Pc::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($pcId);

        if (!$this->pcCanBeBooked($tenantId, $clientId, $pcId)) {
            throw new HttpResponseException(response()->json(['message' => 'PC is already booked by another client or busy now'], 422));
        }

        PcBooking::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'pc_id' => $pcId],
            ['client_id' => $clientId, 'reserved_from' => $startAt, 'reserved_until' => $until],
        );

        return [
            'ok' => true,
            'pc' => [
                'id' => (int) $pc->id,
                'name' => (string) ($pc->code ?? ('PC #' . $pc->id)),
                'zone_key' => $this->queues->zoneKeyForPc($pc),
            ],
            'reserved_from' => $startAt->toIso8601String(),
            'reserved_until' => $until->toIso8601String(),
        ];
    }

    public function quickRebook(int $tenantId, int $clientId, ?string $startAtRaw, ?int $holdMinutes): array
    {
        $startAt = $startAtRaw ? Carbon::parse($startAtRaw) : now();
        if ($startAt->lt(now()->subMinute())) {
            throw new HttpResponseException(response()->json(['message' => 'Start time cannot be in the past'], 422));
        }

        $holdMinutes = (int) ($holdMinutes ?? 60);
        $until = $startAt->copy()->addMinutes($holdMinutes);

        $candidateIds = collect();
        $lastSessionPcId = (int) (Session::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->whereNotNull('pc_id')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->value('pc_id') ?? 0);

        if ($lastSessionPcId > 0) {
            $candidateIds->push($lastSessionPcId);
        }

        $lastBookingPcId = (int) (PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->orderByDesc('reserved_from')
            ->orderByDesc('id')
            ->value('pc_id') ?? 0);

        if ($lastBookingPcId > 0) {
            $candidateIds->push($lastBookingPcId);
        }

        $candidateIds = $candidateIds
            ->map(static fn($value) => (int) $value)
            ->filter(static fn($value) => $value > 0)
            ->unique()
            ->values();

        if ($candidateIds->isEmpty()) {
            throw new HttpResponseException(response()->json(['message' => 'No recent PC found for quick rebook'], 422));
        }

        $sourcePc = Pc::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $candidateIds->all())
            ->with(['zoneRel:id,name'])
            ->get(['id', 'code', 'zone_id', 'zone'])
            ->sortBy(function ($pc) use ($candidateIds) {
                $index = $candidateIds->search((int) $pc->id);
                return $index === false ? 999 : (int) $index;
            })
            ->first();

        if (!$sourcePc) {
            throw new HttpResponseException(response()->json(['message' => 'Recent PC is unavailable'], 422));
        }

        $targetPc = null;
        $fallbackUsed = false;

        foreach ($candidateIds as $pcId) {
            if ($this->pcCanBeBooked($tenantId, $clientId, (int) $pcId)) {
                $targetPc = Pc::query()
                    ->where('tenant_id', $tenantId)
                    ->with(['zoneRel:id,name'])
                    ->find((int) $pcId, ['id', 'code', 'zone_id', 'zone']);
                if ($targetPc) {
                    break;
                }
            }
        }

        if (!$targetPc) {
            $fallbackUsed = true;
            $zoneQuery = Pc::query()
                ->where('tenant_id', $tenantId)
                ->with(['zoneRel:id,name'])
                ->orderByRaw('COALESCE(sort_order, 0)')
                ->orderBy('code');

            $sourceZoneId = (int) ($sourcePc->zone_id ?? 0);
            if ($sourceZoneId > 0) {
                $zoneQuery->where('zone_id', $sourceZoneId);
            } else {
                $sourceZoneName = trim((string) ($sourcePc->zoneRel?->name ?? $sourcePc->zone ?? ''));
                if ($sourceZoneName !== '') {
                    $zoneQuery->whereRaw('LOWER(COALESCE(zone, ?)) = ?', [$sourceZoneName, strtolower($sourceZoneName)]);
                }
            }

            foreach ($zoneQuery->get(['id', 'code', 'zone_id', 'zone']) as $pc) {
                if ($this->pcCanBeBooked($tenantId, $clientId, (int) $pc->id)) {
                    $targetPc = $pc;
                    break;
                }
            }
        }

        if (!$targetPc) {
            $fallbackUsed = true;
            foreach (Pc::query()
                ->where('tenant_id', $tenantId)
                ->with(['zoneRel:id,name'])
                ->orderByRaw('COALESCE(sort_order, 0)')
                ->orderBy('code')
                ->get(['id', 'code', 'zone_id', 'zone']) as $pc) {
                if ($this->pcCanBeBooked($tenantId, $clientId, (int) $pc->id)) {
                    $targetPc = $pc;
                    break;
                }
            }
        }

        if (!$targetPc) {
            throw new HttpResponseException(response()->json(['message' => 'No available PCs right now'], 422));
        }

        PcBooking::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'pc_id' => (int) $targetPc->id],
            ['client_id' => $clientId, 'reserved_from' => $startAt, 'reserved_until' => $until],
        );

        $zoneName = trim((string) ($targetPc->zoneRel?->name ?? $targetPc->zone ?? 'Default'));
        if ($zoneName === '') {
            $zoneName = 'Default';
        }

        return [
            'ok' => true,
            'fallback_used' => $fallbackUsed,
            'source_pc_id' => (int) $sourcePc->id,
            'pc' => [
                'id' => (int) $targetPc->id,
                'name' => (string) ($targetPc->code ?? ('PC #' . $targetPc->id)),
                'zone_key' => $this->queues->zoneKeyForPc($targetPc),
                'zone_name' => $zoneName,
            ],
            'reserved_from' => $startAt->toIso8601String(),
            'reserved_until' => $until->toIso8601String(),
        ];
    }

    public function unbook(int $tenantId, int $clientId, int $pcId): array
    {
        $booking = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->first();

        if (!$booking) {
            return ['ok' => true];
        }

        if ((int) $booking->client_id !== $clientId) {
            throw new HttpResponseException(response()->json(['message' => 'Cannot cancel another client booking'], 403));
        }

        $booking->delete();

        return ['ok' => true];
    }

    public function openByQr(int $tenantId, int $pcId, string $code): array
    {
        $pc = Pc::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($pcId);

        if (property_exists($pc, 'qr_code') && $pc->qr_code) {
            if (!hash_equals((string) $pc->qr_code, $code)) {
                throw new HttpResponseException(response()->json(['message' => 'Invalid QR code'], 422));
            }
        }

        return [
            'ok' => true,
            'message' => 'Open command sent',
            'pc' => [
                'id' => (int) $pc->id,
                'name' => (string) ($pc->code ?? ''),
            ],
        ];
    }

    private function pcCanBeBooked(int $tenantId, int $clientId, int $pcId): bool
    {
        $activeSession = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('status', 'active')
            ->exists();

        if ($activeSession) {
            return false;
        }

        $existing = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('reserved_until', '>', now())
            ->first();

        if ($existing && (int) $existing->client_id !== $clientId) {
            return false;
        }

        return true;
    }
}
