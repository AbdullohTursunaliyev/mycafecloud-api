<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pc;
use App\Models\PcBooking;
use App\Models\Session as PcSession;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobilePcController extends Controller
{
    // GET /api/mobile/pcs
    public function index(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');
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

        $busyPcIds = PcSession::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->pluck('pc_id')
            ->flip();

        $out = [];
        foreach ($pcs as $pc) {
            $booking = $bookings[$pc->id] ?? null;
            $isBusy = $busyPcIds->has($pc->id);
            $isBooked = !$isBusy && $booking !== null;

            $status = $isBusy ? 'busy' : ($isBooked ? 'booked' : 'free');
            $mine = $booking ? ((int)$booking->client_id === $clientId) : false;

            $zoneName = $pc->zoneRel?->name ?? $pc->zone ?? 'Default';
            $zonePrice = (int)($pc->zoneRel?->price_per_hour ?? 0);

            $out[] = [
                'id' => (int)$pc->id,
                'name' => (string)($pc->code ?? ('PC #' . $pc->id)),
                'code' => (string)($pc->code ?? ''),
                'status' => $status,
                'zone' => [
                    'id' => (int)($pc->zone_id ?? 0),
                    'name' => (string)$zoneName,
                    'price_per_hour' => $zonePrice,
                ],
                'booking' => $booking ? [
                    'client_id' => (int)$booking->client_id,
                    'reserved_from' => optional($booking->reserved_from)?->toIso8601String(),
                    'reserved_until' => (string)$booking->reserved_until,
                    'is_mine' => $mine,
                ] : null,
                'can_book' => !$isBusy && (!$booking || $mine),
                'can_unbook' => !$isBusy && $booking && $mine,
            ];
        }

        return response()->json(['pcs' => $out]);
    }

    // POST /api/mobile/pcs/{pcId}/book  { minutes? }
    public function book(Request $request, int $pcId)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $data = $request->validate([
            'start_at' => ['nullable', 'date'],
            'hold_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ]);

        $startAt = isset($data['start_at']) && $data['start_at']
            ? Carbon::parse((string)$data['start_at'])
            : now();
        if ($startAt->lt(now()->subMinute())) {
            return response()->json(['message' => 'Start time cannot be in the past'], 422);
        }
        $holdMinutes = (int)($data['hold_minutes'] ?? 60);

        $pc = Pc::query()->where('tenant_id', $tenantId)->findOrFail($pcId);

        $activeSession = PcSession::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('status', 'active')
            ->exists();

        if ($activeSession) {
            return response()->json(['message' => 'PC is busy now'], 422);
        }

        $existing = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('reserved_until', '>', now())
            ->first();

        if ($existing && (int)$existing->client_id !== $clientId) {
            return response()->json(['message' => 'PC is already booked by another client'], 422);
        }

        $until = $startAt->copy()->addMinutes($holdMinutes);

        PcBooking::updateOrCreate(
            ['tenant_id' => $tenantId, 'pc_id' => $pc->id],
            ['client_id' => $clientId, 'reserved_from' => $startAt, 'reserved_until' => $until]
        );

        return response()->json([
            'ok' => true,
            'reserved_from' => $startAt->toIso8601String(),
            'reserved_until' => $until->toIso8601String(),
        ]);
    }

    // POST /api/mobile/pcs/party-book
    // {
    //   pc_ids: [1,2,3],
    //   start_at?: "2026-02-20T12:30:00Z",
    //   hold_minutes?: 60
    // }
    public function partyBook(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $data = $request->validate([
            'pc_ids' => ['required', 'array', 'min:2', 'max:8'],
            'pc_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'start_at' => ['nullable', 'date'],
            'hold_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ]);

        $pcIds = collect($data['pc_ids'] ?? [])
            ->map(fn($v) => (int)$v)
            ->filter(fn($v) => $v > 0)
            ->unique()
            ->values();

        if ($pcIds->count() < 2) {
            return response()->json(['message' => 'Select at least 2 PCs'], 422);
        }

        $startAt = isset($data['start_at']) && $data['start_at']
            ? Carbon::parse((string)$data['start_at'])
            : now();
        if ($startAt->lt(now()->subMinute())) {
            return response()->json(['message' => 'Start time cannot be in the past'], 422);
        }
        $holdMinutes = (int)($data['hold_minutes'] ?? 60);
        $until = $startAt->copy()->addMinutes($holdMinutes);

        $pcs = Pc::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $pcIds->all())
            ->get(['id', 'zone_id', 'zone', 'code']);

        if ($pcs->count() !== $pcIds->count()) {
            return response()->json(['message' => 'Some PCs were not found'], 404);
        }

        $zoneKeys = $pcs->map(function ($pc) {
            if ((int)($pc->zone_id ?? 0) > 0) {
                return 'id:' . (int)$pc->zone_id;
            }
            $name = mb_strtolower(trim((string)($pc->zone ?? 'default')));
            return 'name:' . ($name !== '' ? $name : 'default');
        })->unique()->values();

        if ($zoneKeys->count() > 1) {
            return response()->json([
                'message' => 'Party booking requires PCs from the same zone',
            ], 422);
        }

        $busyIds = PcSession::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('pc_id', $pcIds->all())
            ->pluck('pc_id')
            ->map(fn($v) => (int)$v)
            ->all();

        if (!empty($busyIds)) {
            return response()->json([
                'message' => 'Some selected PCs are busy now',
                'busy_pc_ids' => array_values($busyIds),
            ], 422);
        }

        $existing = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('pc_id', $pcIds->all())
            ->where('reserved_until', '>', now())
            ->get()
            ->keyBy('pc_id');

        $blocked = [];
        foreach ($pcIds as $pcId) {
            $current = $existing->get((int)$pcId);
            if ($current && (int)$current->client_id !== $clientId) {
                $blocked[] = (int)$pcId;
            }
        }

        if (!empty($blocked)) {
            return response()->json([
                'message' => 'Some selected PCs are already booked',
                'booked_pc_ids' => array_values($blocked),
            ], 422);
        }

        DB::transaction(function () use ($tenantId, $clientId, $pcIds, $startAt, $until) {
            foreach ($pcIds as $pcId) {
                PcBooking::updateOrCreate(
                    ['tenant_id' => $tenantId, 'pc_id' => (int)$pcId],
                    [
                        'client_id' => $clientId,
                        'reserved_from' => $startAt,
                        'reserved_until' => $until,
                    ]
                );
            }
        });

        return response()->json([
            'ok' => true,
            'reserved_count' => $pcIds->count(),
            'pc_ids' => $pcIds->all(),
            'reserved_from' => $startAt->toIso8601String(),
            'reserved_until' => $until->toIso8601String(),
        ]);
    }

    // GET /api/mobile/pcs/smart-seat?zone_key=name:vip1&arrive_in=15&limit=3
    public function smartSeat(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');
        $now = now();

        $zoneKey = trim((string)$request->query('zone_key', ''));
        $arriveIn = max(1, min(90, (int)$request->query('arrive_in', 15)));
        $limit = max(1, min(5, (int)$request->query('limit', 3)));
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

        $busyPcIds = PcSession::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->pluck('pc_id')
            ->map(fn($v) => (int)$v)
            ->flip();

        $freeNow = [];
        $soon = [];
        foreach ($pcs as $pc) {
            $pcId = (int)$pc->id;
            $thisZoneKey = $this->zoneKeyForPc($pc);
            $isPreferredZone = ($zoneKey !== '' && $zoneKey === $thisZoneKey);

            if ($busyPcIds->has($pcId)) {
                continue;
            }

            $booking = $bookings->get($pcId);
            $pcName = trim((string)($pc->code ?? ''));
            if ($pcName === '') {
                $pcName = 'PC #' . $pcId;
            }
            $zoneName = trim((string)($pc->zoneRel?->name ?? $pc->zone ?? 'Default'));
            if ($zoneName === '') {
                $zoneName = 'Default';
            }

            if (!$booking) {
                $freeNow[] = [
                    'pc_id' => $pcId,
                    'pc_name' => $pcName,
                    'zone_key' => $thisZoneKey,
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
            $etaMin = (int)ceil($etaSec / 60);

            if ($etaMin <= $arriveIn) {
                $soon[] = [
                    'pc_id' => $pcId,
                    'pc_name' => $pcName,
                    'zone_key' => $thisZoneKey,
                    'zone_name' => $zoneName,
                    'status' => 'booked',
                    'eta_minutes' => $etaMin,
                    'hold_available' => false,
                    'is_mine' => ((int)$booking->client_id === $clientId),
                    'reserved_until' => optional($booking->reserved_until)->toIso8601String(),
                    'is_preferred_zone' => $isPreferredZone,
                ];
            }
        }

        $freeNowCollection = collect($freeNow);
        if ($zoneKey !== '') {
            $freeNowCollection = $freeNowCollection
                ->sortByDesc(fn($row) => !empty($row['is_preferred_zone']) ? 1 : 0)
                ->values();
        }

        $soonCollection = collect($soon);
        if ($zoneKey !== '') {
            $soonCollection = $soonCollection
                ->sortBy(function ($row) {
                    $preferredOrder = !empty($row['is_preferred_zone']) ? 0 : 1;
                    $etaOrder = (int)($row['eta_minutes'] ?? 9999);
                    return sprintf('%d-%05d', $preferredOrder, $etaOrder);
                })
                ->values();
        } else {
            $soonCollection = $soonCollection->sortBy('eta_minutes')->values();
        }

        $picked = $freeNowCollection
            ->take($limit)
            ->values();

        if ($picked->count() < $limit) {
            $need = $limit - $picked->count();
            $picked = $picked->concat(
                $soonCollection
                    ->take($need)
                    ->values()
            )->values();
        }

        return response()->json([
            'ok' => true,
            'meta' => [
                'arrive_in' => $arriveIn,
                'limit' => $limit,
                'zone_key' => $zoneKey !== '' ? $zoneKey : null,
                'server_now' => $now->toIso8601String(),
                'arrive_until' => $arriveUntil->toIso8601String(),
            ],
            'items' => $picked,
        ]);
    }

    // POST /api/mobile/pcs/smart-seat/hold { pc_id, hold_minutes? }
    public function smartSeatHold(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $data = $request->validate([
            'pc_id' => ['required', 'integer', 'min:1'],
            'hold_minutes' => ['nullable', 'integer', 'min:10', 'max:30'],
        ]);

        $pcId = (int)$data['pc_id'];
        $holdMinutes = (int)($data['hold_minutes'] ?? 15);
        $startAt = now();
        $until = $startAt->copy()->addMinutes($holdMinutes);

        $pc = Pc::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($pcId);

        $activeSession = PcSession::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('status', 'active')
            ->exists();

        if ($activeSession) {
            return response()->json(['message' => 'PC is busy now'], 422);
        }

        $existing = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('reserved_until', '>', now())
            ->first();

        if ($existing && (int)$existing->client_id !== $clientId) {
            return response()->json(['message' => 'PC is already booked by another client'], 422);
        }

        PcBooking::updateOrCreate(
            ['tenant_id' => $tenantId, 'pc_id' => $pcId],
            [
                'client_id' => $clientId,
                'reserved_from' => $startAt,
                'reserved_until' => $until,
            ]
        );

        return response()->json([
            'ok' => true,
            'pc' => [
                'id' => (int)$pc->id,
                'name' => (string)($pc->code ?? ('PC #' . $pc->id)),
                'zone_key' => $this->zoneKeyForPc($pc),
            ],
            'reserved_from' => $startAt->toIso8601String(),
            'reserved_until' => $until->toIso8601String(),
        ]);
    }

    // POST /api/mobile/pcs/rebook-quick { start_at?, hold_minutes? }
    public function quickRebook(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $data = $request->validate([
            'start_at' => ['nullable', 'date'],
            'hold_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ]);

        $startAt = isset($data['start_at']) && $data['start_at']
            ? Carbon::parse((string)$data['start_at'])
            : now();
        if ($startAt->lt(now()->subMinute())) {
            return response()->json(['message' => 'Start time cannot be in the past'], 422);
        }
        $holdMinutes = (int)($data['hold_minutes'] ?? 60);
        $until = $startAt->copy()->addMinutes($holdMinutes);

        $candidateIds = collect();

        $lastSessionPcId = (int)(PcSession::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->whereNotNull('pc_id')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->value('pc_id') ?? 0);
        if ($lastSessionPcId > 0) {
            $candidateIds->push($lastSessionPcId);
        }

        $lastBookingPcId = (int)(PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->orderByDesc('reserved_from')
            ->orderByDesc('id')
            ->value('pc_id') ?? 0);
        if ($lastBookingPcId > 0) {
            $candidateIds->push($lastBookingPcId);
        }

        $candidateIds = $candidateIds->map(fn($v) => (int)$v)->filter(fn($v) => $v > 0)->unique()->values();
        if ($candidateIds->isEmpty()) {
            return response()->json(['message' => 'No recent PC found for quick rebook'], 422);
        }

        $sourcePc = Pc::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $candidateIds->all())
            ->with(['zoneRel:id,name'])
            ->get(['id', 'code', 'zone_id', 'zone'])
            ->sortBy(function ($pc) use ($candidateIds) {
                $idx = $candidateIds->search((int)$pc->id);
                return $idx === false ? 999 : (int)$idx;
            })
            ->first();

        if (!$sourcePc) {
            return response()->json(['message' => 'Recent PC is unavailable'], 422);
        }

        $targetPc = null;
        $fallbackUsed = false;

        foreach ($candidateIds as $pcId) {
            if ($this->pcCanBeBooked($tenantId, $clientId, (int)$pcId)) {
                $targetPc = Pc::query()
                    ->where('tenant_id', $tenantId)
                    ->with(['zoneRel:id,name'])
                    ->find((int)$pcId, ['id', 'code', 'zone_id', 'zone']);
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

            $sourceZoneId = (int)($sourcePc->zone_id ?? 0);
            if ($sourceZoneId > 0) {
                $zoneQuery->where('zone_id', $sourceZoneId);
            } else {
                $sourceZoneName = trim((string)($sourcePc->zoneRel?->name ?? $sourcePc->zone ?? ''));
                if ($sourceZoneName !== '') {
                    $zoneQuery->whereRaw('LOWER(COALESCE(zone, ?)) = ?', [$sourceZoneName, strtolower($sourceZoneName)]);
                }
            }

            $zonePcs = $zoneQuery->get(['id', 'code', 'zone_id', 'zone']);
            foreach ($zonePcs as $pc) {
                if ($this->pcCanBeBooked($tenantId, $clientId, (int)$pc->id)) {
                    $targetPc = $pc;
                    break;
                }
            }
        }

        if (!$targetPc) {
            $fallbackUsed = true;
            $allPcs = Pc::query()
                ->where('tenant_id', $tenantId)
                ->with(['zoneRel:id,name'])
                ->orderByRaw('COALESCE(sort_order, 0)')
                ->orderBy('code')
                ->get(['id', 'code', 'zone_id', 'zone']);
            foreach ($allPcs as $pc) {
                if ($this->pcCanBeBooked($tenantId, $clientId, (int)$pc->id)) {
                    $targetPc = $pc;
                    break;
                }
            }
        }

        if (!$targetPc) {
            return response()->json(['message' => 'No available PCs right now'], 422);
        }

        PcBooking::updateOrCreate(
            ['tenant_id' => $tenantId, 'pc_id' => (int)$targetPc->id],
            [
                'client_id' => $clientId,
                'reserved_from' => $startAt,
                'reserved_until' => $until,
            ]
        );

        $zoneName = trim((string)($targetPc->zoneRel?->name ?? $targetPc->zone ?? 'Default'));
        if ($zoneName === '') {
            $zoneName = 'Default';
        }

        return response()->json([
            'ok' => true,
            'fallback_used' => $fallbackUsed,
            'source_pc_id' => (int)$sourcePc->id,
            'pc' => [
                'id' => (int)$targetPc->id,
                'name' => (string)($targetPc->code ?? ('PC #' . $targetPc->id)),
                'zone_key' => $this->zoneKeyForPc($targetPc),
                'zone_name' => $zoneName,
            ],
            'reserved_from' => $startAt->toIso8601String(),
            'reserved_until' => $until->toIso8601String(),
        ]);
    }

    // GET /api/mobile/client/smart-queue
    public function smartQueueIndex(Request $request)
    {
        if (!Schema::hasTable('mobile_smart_queue')) {
            return response()->json([
                'items' => [],
                'notifications' => [],
            ]);
        }

        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');
        $now = now();

        $rows = DB::table('mobile_smart_queue')
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->whereIn('status', ['waiting', 'notified'])
            ->orderBy('id')
            ->get([
                'id',
                'zone_key',
                'notify_on_free',
                'status',
                'notified_at',
                'created_at',
                'updated_at',
            ]);

        $items = [];
        $notifications = [];

        foreach ($rows as $row) {
            $zoneKey = $this->normalizeZoneKey($row->zone_key ? (string)$row->zone_key : null);
            $positionQuery = DB::table('mobile_smart_queue')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['waiting', 'notified'])
                ->where('id', '<=', (int)$row->id);
            if ($zoneKey === null) {
                $positionQuery->whereNull('zone_key');
            } else {
                $positionQuery->where('zone_key', $zoneKey);
            }
            $position = (int)$positionQuery->count();

            $snapshot = $this->queueZoneSnapshot($tenantId, $zoneKey);
            $readyNow = (int)($snapshot['free_now'] ?? 0) > 0;
            $notifyOnFree = (bool)$row->notify_on_free;
            $notifiedAt = $row->notified_at ? Carbon::parse((string)$row->notified_at) : null;
            $needsNotify = $notifyOnFree && $readyNow && $notifiedAt === null;

            if ($needsNotify) {
                DB::table('mobile_smart_queue')
                    ->where('id', (int)$row->id)
                    ->update([
                        'status' => 'notified',
                        'notified_at' => $now,
                        'updated_at' => $now,
                    ]);
                $notifiedAt = $now;
                $notifications[] = [
                    'id' => 'smart-queue-' . (int)$row->id . '-' . $now->timestamp,
                    'type' => 'smart_queue_ready',
                    'queue_id' => (int)$row->id,
                    'zone_key' => $zoneKey,
                    'zone_name' => (string)($snapshot['zone_name'] ?? ''),
                    'message' => 'Smart queue: free PC is available now.',
                ];
            }

            $items[] = [
                'id' => (int)$row->id,
                'zone_key' => $zoneKey,
                'zone_name' => (string)($snapshot['zone_name'] ?? ''),
                'position' => max(1, $position),
                'status' => $needsNotify ? 'notified' : (string)$row->status,
                'notify_on_free' => $notifyOnFree,
                'ready_now' => $readyNow,
                'free_now' => (int)($snapshot['free_now'] ?? 0),
                'eta_min' => (int)($snapshot['eta_min'] ?? 0),
                'created_at' => $row->created_at ? (string)$row->created_at : null,
                'updated_at' => $row->updated_at ? (string)$row->updated_at : null,
                'notified_at' => $notifiedAt ? $notifiedAt->toIso8601String() : null,
            ];
        }

        return response()->json([
            'items' => $items,
            'notifications' => $notifications,
        ]);
    }

    // POST /api/mobile/client/smart-queue/join
    public function smartQueueJoin(Request $request)
    {
        if (!Schema::hasTable('mobile_smart_queue')) {
            return response()->json(['message' => 'Smart queue is not ready yet'], 422);
        }

        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $data = $request->validate([
            'zone_key' => ['nullable', 'string', 'max:96'],
            'notify_on_free' => ['nullable', 'boolean'],
        ]);

        $zoneKey = $this->normalizeZoneKey($data['zone_key'] ?? null);
        $notifyOnFree = array_key_exists('notify_on_free', $data)
            ? (bool)$data['notify_on_free']
            : true;
        $now = now();

        $existingQuery = DB::table('mobile_smart_queue')
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->whereIn('status', ['waiting', 'notified']);
        if ($zoneKey === null) {
            $existingQuery->whereNull('zone_key');
        } else {
            $existingQuery->where('zone_key', $zoneKey);
        }
        $existing = $existingQuery->orderByDesc('id')->first(['id']);

        if ($existing) {
            DB::table('mobile_smart_queue')
                ->where('id', (int)$existing->id)
                ->update([
                    'status' => 'waiting',
                    'notify_on_free' => $notifyOnFree,
                    'notified_at' => null,
                    'updated_at' => $now,
                ]);
            $rowId = (int)$existing->id;
        } else {
            $rowId = (int)DB::table('mobile_smart_queue')->insertGetId([
                'tenant_id' => $tenantId,
                'client_id' => $clientId,
                'zone_key' => $zoneKey,
                'notify_on_free' => $notifyOnFree,
                'status' => 'waiting',
                'notified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $positionQuery = DB::table('mobile_smart_queue')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['waiting', 'notified'])
            ->where('id', '<=', $rowId);
        if ($zoneKey === null) {
            $positionQuery->whereNull('zone_key');
        } else {
            $positionQuery->where('zone_key', $zoneKey);
        }
        $position = (int)$positionQuery->count();

        $snapshot = $this->queueZoneSnapshot($tenantId, $zoneKey);

        return response()->json([
            'ok' => true,
            'queue' => [
                'id' => $rowId,
                'zone_key' => $zoneKey,
                'zone_name' => (string)($snapshot['zone_name'] ?? ''),
                'position' => max(1, $position),
                'status' => 'waiting',
                'notify_on_free' => $notifyOnFree,
                'ready_now' => (int)($snapshot['free_now'] ?? 0) > 0,
                'free_now' => (int)($snapshot['free_now'] ?? 0),
                'eta_min' => (int)($snapshot['eta_min'] ?? 0),
            ],
        ]);
    }

    // DELETE /api/mobile/client/smart-queue/{id}
    public function smartQueueCancel(Request $request, int $id)
    {
        if (!Schema::hasTable('mobile_smart_queue')) {
            return response()->json(['ok' => true]);
        }

        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $affected = DB::table('mobile_smart_queue')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->whereIn('status', ['waiting', 'notified'])
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

        return response()->json([
            'ok' => true,
            'cancelled' => $affected > 0,
        ]);
    }

    // DELETE /api/mobile/pcs/{pcId}/book
    public function unbook(Request $request, int $pcId)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $booking = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->first();

        if (!$booking) {
            return response()->json(['ok' => true]);
        }

        if ((int)$booking->client_id !== $clientId) {
            return response()->json(['message' => 'Cannot cancel another client booking'], 403);
        }

        $booking->delete();
        return response()->json(['ok' => true]);
    }

    // POST /api/mobile/pcs/open  { pc_id, code }
    public function openByQr(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');

        $data = $request->validate([
            'pc_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:128'],
        ]);

        $pc = Pc::query()->where('tenant_id', $tenantId)->findOrFail((int)$data['pc_id']);

        if (property_exists($pc, 'qr_code') && $pc->qr_code) {
            if (!hash_equals((string)$pc->qr_code, (string)$data['code'])) {
                return response()->json(['message' => 'Invalid QR code'], 422);
            }
        }

        return response()->json([
            'ok' => true,
            'message' => 'Open command sent',
            'pc' => ['id' => (int)$pc->id, 'name' => (string)($pc->code ?? '')],
        ]);
    }

    private function zoneKeyForPc(Pc $pc): string
    {
        $zoneId = (int)($pc->zone_id ?? 0);
        if ($zoneId > 0) {
            return 'id:' . $zoneId;
        }

        $name = trim((string)($pc->zoneRel?->name ?? $pc->zone ?? 'Default'));
        if ($name === '') {
            $name = 'Default';
        }
        $name = strtolower($name);
        return 'name:' . $name;
    }

    private function normalizeZoneKey(?string $zoneKey): ?string
    {
        $raw = trim((string)$zoneKey);
        if ($raw === '') {
            return null;
        }
        return strtolower($raw);
    }

    private function queueZoneSnapshot(int $tenantId, ?string $zoneKey): array
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
            $name = trim((string)($pc->zoneRel?->name ?? $pc->zone ?? 'Default'));
            if ($name === '') {
                $name = 'Default';
            }
            $pcMeta[(int)$pc->id] = [
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

        $busyRows = PcSession::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('pc_id', $pcIds)
            ->get(['pc_id', 'started_at']);
        $busySet = [];
        foreach ($busyRows as $row) {
            $busySet[(int)$row->pc_id] = $row;
        }

        $bookingRows = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('pc_id', $pcIds)
            ->where('reserved_until', '>', $now)
            ->get(['pc_id', 'reserved_until']);
        $bookingSet = [];
        foreach ($bookingRows as $row) {
            $bookingSet[(int)$row->pc_id] = $row;
        }

        $freeNow = 0;
        foreach ($pcIds as $pcId) {
            if (isset($busySet[$pcId])) {
                continue;
            }
            if (isset($bookingSet[$pcId])) {
                continue;
            }
            $freeNow++;
        }

        if ($freeNow > 0) {
            return [
                'zone_key' => $zoneKey,
                'zone_name' => (string)$zoneName,
                'free_now' => $freeNow,
                'eta_min' => 0,
            ];
        }

        $avgDuration = 120;
        $endedRows = PcSession::query()
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
                $start = Carbon::parse((string)$row->started_at);
                $end = Carbon::parse((string)$row->ended_at);
                if ($end->lessThanOrEqualTo($start)) {
                    continue;
                }
                $sum += max(1, (int)$start->diffInMinutes($end));
                $count++;
            }
            if ($count > 0) {
                $avgDuration = max(30, (int)round($sum / $count));
            }
        }

        $etas = [];
        foreach ($busyRows as $row) {
            $startedAt = $row->started_at ? Carbon::parse((string)$row->started_at) : null;
            if (!$startedAt) {
                continue;
            }
            $elapsed = (int)$startedAt->diffInMinutes($now);
            $etas[] = max(5, $avgDuration - $elapsed);
        }
        foreach ($bookingRows as $row) {
            $remainSec = $now->diffInSeconds(Carbon::parse((string)$row->reserved_until), false);
            $etas[] = max(1, (int)ceil($remainSec / 60));
        }
        $etaMin = !empty($etas) ? max(1, min($etas)) : max(5, (int)round($avgDuration / 2));

        return [
            'zone_key' => $zoneKey,
            'zone_name' => (string)$zoneName,
            'free_now' => 0,
            'eta_min' => $etaMin,
        ];
    }

    private function pcCanBeBooked(int $tenantId, int $clientId, int $pcId): bool
    {
        $activeSession = PcSession::query()
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

        if ($existing && (int)$existing->client_id !== $clientId) {
            return false;
        }

        return true;
    }
}
