<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Pc;
use App\Models\PcHeartbeat;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PcController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $q = Pc::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'zoneRel:id,tenant_id,name,price_per_hour',
                'activeSession.tariff',
                'activeSession.client:id,tenant_id,phone,login,balance',
            ])
            // sort by zone name (fallback to legacy string)
            ->orderByRaw("COALESCE((SELECT name FROM zones WHERE zones.id = pcs.zone_id), pcs.zone) NULLS LAST")
            ->orderBy('code');

        if ($request->filled('zone_id')) {
            $q->where('zone_id', (int)$request->input('zone_id'));
        } elseif ($request->filled('zone')) {
            $q->where('zone', $request->string('zone'));
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where('code', 'ILIKE', "%{$s}%");
        }

        $pcsRaw = $q->get();

        $latestHeartbeatIds = PcHeartbeat::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('pc_id', $pcsRaw->pluck('id'))
            ->selectRaw('MAX(id) as id')
            ->groupBy('pc_id');

        $latestHeartbeatsByPcId = PcHeartbeat::query()
            ->whereIn('id', $latestHeartbeatIds)
            ->get(['pc_id', 'received_at', 'metrics'])
            ->keyBy('pc_id');

        $now = now();
        $activeBookings = Booking::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->whereIn('pc_id', $pcsRaw->pluck('id'))
            ->get(['id', 'pc_id', 'client_id', 'start_at', 'end_at'])
            ->keyBy('pc_id');

        $pcs = $pcsRaw->map(function (Pc $pc) use ($latestHeartbeatsByPcId) {
            $active = $pc->activeSession;
            $zoneName = $pc->zoneRel?->name ?? $pc->zone;
            $zonePrice = $pc->zoneRel?->price_per_hour;
            $clientBalance = $active?->client?->balance;
            $latestHeartbeat = $latestHeartbeatsByPcId->get($pc->id);
            $metrics = is_array($latestHeartbeat?->metrics) ? $latestHeartbeat->metrics : [];

            return [
                'id' => $pc->id,
                'code' => $pc->code,
                'zone_id' => $pc->zone_id,
                'zone' => $zoneName,
                'zone_price_per_hour' => $zonePrice,
                'status' => $pc->status,
                'ip_address' => $pc->ip_address,
                'last_seen_at' => optional($pc->last_seen_at)?->toIso8601String(),
                'telemetry' => [
                    'received_at' => optional($latestHeartbeat?->received_at)?->toIso8601String(),
                    'cpu_name' => $metrics['cpu_name'] ?? null,
                    'ram_total_mb' => isset($metrics['ram_total_mb']) ? (int)$metrics['ram_total_mb'] : null,
                    'gpu_name' => $metrics['gpu_name'] ?? null,
                    'mac_address' => $metrics['mac_address'] ?? null,
                    'ip_address' => $metrics['ip_address'] ?? null,
                    'disks' => isset($metrics['disks']) && is_array($metrics['disks']) ? array_values($metrics['disks']) : [],
                ],
                'client_balance' => $clientBalance,
                'active_session' => $active ? [
                    'id' => $active->id,
                    'started_at' => $active->started_at->toIso8601String(),
                    'tariff' => $active->tariff ? [
                        'id' => $active->tariff->id,
                        'name' => $active->tariff->name,
                        'price_per_hour' => $active->tariff->price_per_hour,
                    ] : null,
                    'client' => $active->client ? [
                        'id' => $active->client->id,
                        'account_id' => $active->client->account_id,
                        'phone' => $active->client->phone,
                        'login' => $active->client->login,
                        'balance' => $active->client->balance,
                    ] : null,
                ] : null,
            ];
        });

        $pcs = $pcs->map(function (array $row) use ($activeBookings) {
            $booking = $activeBookings->get((int)$row['id']);
            $hasActiveSession = !empty($row['active_session']);

            if ($booking && !$hasActiveSession) {
                $row['status'] = 'reserved';
            } elseif ($hasActiveSession) {
                $row['status'] = 'busy';
            }

            $row['current_booking'] = $booking ? [
                'id' => (int)$booking->id,
                'client_id' => (int)$booking->client_id,
                'start_at' => optional($booking->start_at)->toIso8601String(),
                'end_at' => optional($booking->end_at)->toIso8601String(),
            ] : null;

            return $row;
        });

        return response()->json(['data' => $pcs]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'code' => ['required','string','max:32', Rule::unique('pcs')->where(fn($q) => $q->where('tenant_id',$tenantId))],
            'zone_id' => ['nullable','integer', Rule::exists('zones','id')->where(fn($q) => $q->where('tenant_id',$tenantId))],
            'zone' => ['nullable','string','max:120'],
            'ip_address' => ['nullable','ip'],
            'status' => ['nullable','string','in:offline,online,busy,reserved,maintenance,locked'],
            'pos_x' => ['nullable','integer','min:0','max:10000'],
            'pos_y' => ['nullable','integer','min:0','max:10000'],
            'group' => ['nullable','string','max:50'],
            'sort_order' => ['nullable','integer','min:0','max:100000'],
            'notes' => ['nullable','string','max:255'],
            'is_hidden' => ['nullable','boolean'],
        ]);

        $zoneId = $data['zone_id'] ?? null;
        $zoneName = $data['zone'] ?? null;

        if (!$zoneId && $zoneName) {
            $zoneId = Zone::query()
                ->where('tenant_id', $tenantId)
                ->where('name', $zoneName)
                ->value('id');
        }
        if ($zoneId && !$zoneName) {
            $zoneName = Zone::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $zoneId)
                ->value('name');
        }

        $pc = Pc::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'zone_id' => $zoneId,
            'zone' => $zoneName,
            'ip_address' => $data['ip_address'] ?? null,
            'status' => $data['status'] ?? 'offline',
            'pos_x' => $data['pos_x'] ?? null,
            'pos_y' => $data['pos_y'] ?? null,
            'group' => $data['group'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'is_hidden' => (bool)($data['is_hidden'] ?? false),
        ]);

        $pc->load(['zoneRel:id,tenant_id,name,price_per_hour']);

        $zoneName = $pc->zoneRel?->name ?? $pc->zone;

        return response()->json([
            'data' => [
                'id' => $pc->id,
                'code' => $pc->code,
                'zone_id' => $pc->zone_id,
                'zone' => $zoneName,
                'zone_price_per_hour' =>  $pc->zoneRel?->price_per_hour,
                'status' => $pc->status,
                'ip_address' => $pc->ip_address,
                'last_seen_at' => optional($pc->last_seen_at)?->toIso8601String(),
                'client_balance' => null,
                'active_session' => null,
            ],
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;

        $pc = Pc::where('tenant_id', $tenantId)->findOrFail($id);

        $data = $request->validate([
            'code' => ['sometimes','string','max:32', Rule::unique('pcs')->ignore($pc->id)->where(fn($q) => $q->where('tenant_id',$tenantId))],
            'zone_id' => ['sometimes','nullable','integer', Rule::exists('zones','id')->where(fn($q) => $q->where('tenant_id',$tenantId))],
            'zone' => ['sometimes','nullable','string','max:120'],
            'ip_address' => ['sometimes','nullable','ip'],
            'status' => ['sometimes','string','in:offline,online,busy,reserved,maintenance,locked'],
            'pos_x' => ['sometimes','nullable','integer','min:0','max:10000'],
            'pos_y' => ['sometimes','nullable','integer','min:0','max:10000'],
            'group' => ['sometimes','nullable','string','max:50'],
            'sort_order' => ['sometimes','integer','min:0','max:100000'],
            'notes' => ['sometimes','nullable','string','max:255'],
            'is_hidden' => ['sometimes','boolean'],
        ]);

        if (array_key_exists('zone_id', $data) || array_key_exists('zone', $data)) {
            $zoneId = $data['zone_id'] ?? $pc->zone_id;
            $zoneName = $data['zone'] ?? $pc->zone;

            if (array_key_exists('zone_id', $data) && !$zoneName && $zoneId) {
                $zoneName = Zone::query()->where('tenant_id', $tenantId)->where('id', $zoneId)->value('name');
            }
            if (array_key_exists('zone', $data) && !$zoneId && $zoneName) {
                $zoneId = Zone::query()->where('tenant_id', $tenantId)->where('name', $zoneName)->value('id');
            }

            $data['zone_id'] = $zoneId;
            $data['zone'] = $zoneName;
        }

        $pc->fill($data);
        $pc->save();

        $pc->load(['zone:id,tenant_id,name,price_per_hour']);

        $zoneName = $pc->zone?->name ?? $pc->zone;

        return response()->json([
            'data' => [
                'id' => $pc->id,
                'code' => $pc->code,
                'zone_id' => $pc->zone_id,
                'zone' => $zoneName,
                'zone_price_per_hour' => $pc->zone?->price_per_hour,
                'status' => $pc->status,
                'ip_address' => $pc->ip_address,
                'last_seen_at' => optional($pc->last_seen_at)?->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;

        $pc = Pc::where('tenant_id', $tenantId)->findOrFail($id);
        $pc->delete();

        return response()->json(['ok' => true]);
    }

    public function layoutBatchUpdate(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'items' => ['required','array','max:500'],
            'items.*.id' => ['required','integer'],
            'items.*.pos_x' => ['nullable','integer','min:0','max:10000'],
            'items.*.pos_y' => ['nullable','integer','min:0','max:10000'],
            'items.*.sort_order' => ['nullable','integer','min:0','max:100000'],
            'items.*.zone_id' => ['nullable','integer', Rule::exists('zones','id')->where(fn($q) => $q->where('tenant_id',$tenantId))],
            'items.*.zone' => ['nullable','string','max:120'],
            'items.*.group' => ['nullable','string','max:50'],
        ]);

        foreach ($data['items'] as $item) {
            $pc = Pc::where('tenant_id', $tenantId)
                ->where('id', $item['id'])
                ->first();

            if (!$pc) {
                continue;
            }

            $zoneId = $pc->zone_id;
            $zoneName = $pc->zone;

            $hasZoneId = array_key_exists('zone_id', $item);
            $hasZoneName = array_key_exists('zone', $item);

            if ($hasZoneId || $hasZoneName) {
                $zoneId = $hasZoneId ? ($item['zone_id'] ?? null) : $zoneId;
                $zoneName = $hasZoneName ? ($item['zone'] ?? null) : $zoneName;

                if (!$zoneId && $zoneName) {
                    $zoneId = Zone::query()
                        ->where('tenant_id', $tenantId)
                        ->where('name', $zoneName)
                        ->value('id');
                }
                if ($zoneId && !$zoneName) {
                    $zoneName = Zone::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $zoneId)
                        ->value('name');
                }
            }

            $pc->update([
                'pos_x' => array_key_exists('pos_x', $item) ? ($item['pos_x'] ?? null) : $pc->pos_x,
                'pos_y' => array_key_exists('pos_y', $item) ? ($item['pos_y'] ?? null) : $pc->pos_y,
                'sort_order' => array_key_exists('sort_order', $item) ? ($item['sort_order'] ?? 0) : $pc->sort_order,
                'zone_id' => $zoneId,
                'zone' => $zoneName,
                'group' => array_key_exists('group', $item) ? ($item['group'] ?? null) : $pc->group,
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
