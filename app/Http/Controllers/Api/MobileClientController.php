<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\Event;
use App\Models\MobileUser;
use App\Models\Package;
use App\Models\Pc;
use App\Models\PcBooking;
use App\Models\Session;
use App\Models\SubscriptionPlan;
use App\Services\ClientRankService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MobileClientController extends Controller
{
    // GET /api/mobile/client/summary
    public function summary(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $client = Client::query()->where('tenant_id', $tenantId)->findOrFail($clientId);

        $totalTopup = (int)ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('type', 'topup')
            ->sum('amount');

        $rank = ClientRankService::byTotalTopup($totalTopup);
        $leaderboard = $this->buildLeaderboard($tenantId, $clientId);

        $activePackage = DB::table('client_packages')
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();

        $activeSub = null;
        if (Schema::hasTable('client_subscriptions')) {
            $activeSub = DB::table('client_subscriptions')
                ->where('tenant_id', $tenantId)
                ->where('client_id', $clientId)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->orderByDesc('id')
                ->first();
        }

        $activity = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'type', 'amount', 'bonus_amount', 'payment_method', 'created_at'])
            ->map(function ($t) {
                $type = (string)$t->type;
                $title = match ($type) {
                    'topup' => 'Topup',
                    'package' => 'Package purchase',
                    'subscription' => 'Subscription',
                    'tier_bonus' => 'Rank bonus',
                    'mission_bonus' => 'Mission reward',
                    default => $type,
                };

                return [
                    'id' => (int)$t->id,
                    'type' => $type,
                    'title' => $title,
                    'amount' => (int)$t->amount,
                    'bonus_amount' => (int)($t->bonus_amount ?? 0),
                    'payment_method' => (string)($t->payment_method ?? ''),
                    'created_at' => (string)$t->created_at,
                ];
            })
            ->values()
            ->all();

        $missions = $this->buildMissions($tenantId, $clientId);
        $radar = $this->buildFriendRadar($tenantId, $clientId);
        $bundleHints = $this->buildBundleHints($tenantId);
        $smartQueue = $this->buildSmartQueue($tenantId, $clientId);
        $sessionHighlights = $this->buildSessionHighlights($tenantId, $clientId);

        return response()->json([
            'client' => [
                'id' => (int)$client->id,
                'login' => (string)$client->login,
                'balance' => (int)$client->balance,
                'bonus' => (int)$client->bonus,
            ],
            'rank' => $rank,
            'leaderboard' => $leaderboard,
            'active' => [
                'package' => $activePackage ? [
                    'package_id' => (int)$activePackage->package_id,
                    'remaining_min' => (int)$activePackage->remaining_min,
                    'expires_at' => $activePackage->expires_at ? (string)$activePackage->expires_at : null,
                ] : null,
                'subscription' => $activeSub ? [
                    'subscription_id' => (int)$activeSub->id,
                    'plan_id' => (int)($activeSub->plan_id ?? 0),
                    'ends_at' => $activeSub->ends_at ? (string)$activeSub->ends_at : null,
                    'status' => (string)($activeSub->status ?? 'active'),
                ] : null,
            ],
            'activity' => $activity,
            'missions' => $missions,
            'radar' => $radar,
            'bundle_hints' => $bundleHints,
            'smart_queue' => $smartQueue,
            'session_highlights' => $sessionHighlights,
        ]);
    }

    // POST /api/mobile/client/missions/{code}/claim
    public function claimMission(Request $request, string $code)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');
        $code = trim(strtolower($code));

        $missions = $this->buildMissions($tenantId, $clientId);
        $mission = collect($missions['items'] ?? [])->firstWhere('code', $code);

        if (!$mission) {
            return response()->json(['message' => 'Mission not found'], 404);
        }

        if (!($mission['complete'] ?? false)) {
            return response()->json(['message' => 'Mission is not complete yet'], 422);
        }

        if (($mission['claimed'] ?? false) || !($mission['can_claim'] ?? false)) {
            return response()->json(['message' => 'Mission is already claimed'], 422);
        }

        $rewardBonus = (int)($mission['reward_bonus'] ?? 0);
        if ($rewardBonus <= 0) {
            return response()->json(['message' => 'Mission has no reward'], 422);
        }

        $dayStart = now()->startOfDay();

        DB::transaction(function () use ($tenantId, $clientId, $code, $rewardBonus, $dayStart) {
            $client = Client::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $clientId)
                ->lockForUpdate()
                ->firstOrFail();

            $claimedEvents = Event::query()
                ->where('tenant_id', $tenantId)
                ->where('entity_type', 'client')
                ->where('entity_id', $clientId)
                ->where('type', 'mobile_mission_claim')
                ->where('created_at', '>=', $dayStart)
                ->get(['payload']);

            foreach ($claimedEvents as $event) {
                $payload = $this->eventPayload($event->payload);
                if (($payload['code'] ?? null) === $code) {
                    throw ValidationException::withMessages([
                        'mission' => 'Mission already claimed',
                    ]);
                }
            }

            $client->bonus = (int)$client->bonus + $rewardBonus;
            $client->save();

            Event::query()->create([
                'tenant_id' => $tenantId,
                'type' => 'mobile_mission_claim',
                'source' => 'mobile',
                'entity_type' => 'client',
                'entity_id' => $clientId,
                'payload' => [
                    'code' => $code,
                    'reward_bonus' => $rewardBonus,
                ],
            ]);
        });

        $client = Client::query()->where('tenant_id', $tenantId)->findOrFail($clientId);

        return response()->json([
            'ok' => true,
            'code' => $code,
            'reward_bonus' => $rewardBonus,
            'client_bonus' => (int)$client->bonus,
        ]);
    }

    private function buildLeaderboard(int $tenantId, int $clientId): array
    {
        $sumSub = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'topup')
            ->selectRaw('client_id, COALESCE(SUM(amount), 0) as total_topup')
            ->groupBy('client_id');

        $rows = Client::query()
            ->from('clients as c')
            ->leftJoinSub($sumSub, 'tt', function ($join) {
                $join->on('tt.client_id', '=', 'c.id');
            })
            ->where('c.tenant_id', $tenantId)
            ->where('c.status', 'active')
            ->orderByRaw('COALESCE(tt.total_topup, 0) DESC')
            ->orderBy('c.id')
            ->get([
                'c.id',
                'c.login',
                DB::raw('COALESCE(tt.total_topup, 0) as total_topup'),
            ]);

        $totalPlayers = (int)$rows->count();
        $myPosition = null;
        foreach ($rows as $index => $row) {
            if ((int)$row->id === $clientId) {
                $myPosition = $index + 1;
                break;
            }
        }

        $top = $rows->take(10)->values()->map(function ($row, int $index) {
            $topup = (int)($row->total_topup ?? 0);
            $rank = ClientRankService::byTotalTopup($topup);
            $current = is_array($rank['current'] ?? null) ? $rank['current'] : [];
            return [
                'position' => $index + 1,
                'client_id' => (int)$row->id,
                'login' => (string)($row->login ?? ('#' . $row->id)),
                'total_topup' => $topup,
                'rank' => [
                    'key' => (string)($current['key'] ?? ''),
                    'name' => (string)($current['name'] ?? ''),
                    'color' => (string)($current['color'] ?? ''),
                    'icon' => (string)($current['icon'] ?? ''),
                ],
            ];
        })->all();

        return [
            'position' => $myPosition,
            'total_players' => $totalPlayers,
            'top' => $top,
        ];
    }

    private function missionDefinitions(): array
    {
        return [
            [
                'code' => 'topup_100k',
                'title_key' => 'mission_topup_100k',
                'metric' => 'topup_today',
                'target' => 100000,
                'unit' => 'uzs',
                'reward_bonus' => 5000,
            ],
            [
                'code' => 'play_120m',
                'title_key' => 'mission_play_120m',
                'metric' => 'play_minutes_today',
                'target' => 120,
                'unit' => 'min',
                'reward_bonus' => 7000,
            ],
            [
                'code' => 'book_1',
                'title_key' => 'mission_booking_1',
                'metric' => 'bookings_today',
                'target' => 1,
                'unit' => 'count',
                'reward_bonus' => 3000,
            ],
        ];
    }

    private function buildMissions(int $tenantId, int $clientId): array
    {
        $dayStart = now()->startOfDay();
        $counters = $this->missionCounters($tenantId, $clientId, $dayStart);

        $claimedCodes = [];
        $claimedRows = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', 'client')
            ->where('entity_id', $clientId)
            ->where('type', 'mobile_mission_claim')
            ->where('created_at', '>=', $dayStart)
            ->get(['payload']);

        foreach ($claimedRows as $row) {
            $payload = $this->eventPayload($row->payload);
            $code = trim(strtolower((string)($payload['code'] ?? '')));
            if ($code !== '') {
                $claimedCodes[$code] = true;
            }
        }

        $items = [];
        foreach ($this->missionDefinitions() as $def) {
            $code = (string)$def['code'];
            $target = (int)$def['target'];
            $metric = (string)$def['metric'];
            $progress = (int)($counters[$metric] ?? 0);
            $complete = $progress >= $target;
            $claimed = isset($claimedCodes[$code]);

            $items[] = [
                'code' => $code,
                'title_key' => (string)$def['title_key'],
                'unit' => (string)$def['unit'],
                'target' => $target,
                'progress' => min($progress, $target),
                'raw_progress' => $progress,
                'progress_percent' => $target > 0 ? min(100, (int)floor(($progress * 100) / $target)) : 0,
                'reward_bonus' => (int)$def['reward_bonus'],
                'complete' => $complete,
                'claimed' => $claimed,
                'can_claim' => $complete && !$claimed,
            ];
        }

        $allDone = collect($items)->every(fn($x) => ($x['claimed'] ?? false) === true);
        return [
            'day_start' => $dayStart->toIso8601String(),
            'items' => $items,
            'all_claimed_today' => $allDone,
        ];
    }

    private function missionCounters(int $tenantId, int $clientId, Carbon $dayStart): array
    {
        $topupToday = (int)ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('type', 'topup')
            ->where('created_at', '>=', $dayStart)
            ->sum('amount');

        $bookingsToday = (int)PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', $dayStart)
            ->count();

        $sessionRows = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where(function ($q) use ($dayStart) {
                $q->where('started_at', '>=', $dayStart)
                    ->orWhere('ended_at', '>=', $dayStart)
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'active')->whereNull('ended_at');
                    });
            })
            ->get(['started_at', 'ended_at', 'status']);

        $playMinutes = 0;
        foreach ($sessionRows as $s) {
            $startedAt = $s->started_at ? Carbon::parse((string)$s->started_at) : null;
            if (!$startedAt) {
                continue;
            }
            $start = $startedAt->greaterThan($dayStart) ? $startedAt : $dayStart;
            $end = $s->ended_at ? Carbon::parse((string)$s->ended_at) : now();
            if ($end->greaterThan($start)) {
                $playMinutes += (int)$start->diffInMinutes($end);
            }
        }

        return [
            'topup_today' => max(0, $topupToday),
            'play_minutes_today' => max(0, $playMinutes),
            'bookings_today' => max(0, $bookingsToday),
        ];
    }

    private function buildFriendRadar(int $tenantId, int $clientId): array
    {
        if (!Schema::hasTable('mobile_friendships')) {
            return [
                'online_total' => 0,
                'friends_online' => 0,
                'pending_requests' => 0,
                'items' => [],
            ];
        }

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->find($clientId, ['id', 'login']);
        if (!$client) {
            return [
                'online_total' => 0,
                'friends_online' => 0,
                'pending_requests' => 0,
                'items' => [],
            ];
        }

        $meMobileUser = MobileUser::query()
            ->where('login', (string)$client->login)
            ->first(['id', 'login']);
        if (!$meMobileUser) {
            return [
                'online_total' => 0,
                'friends_online' => 0,
                'pending_requests' => 0,
                'items' => [],
            ];
        }

        $meMobileUserId = (int)$meMobileUser->id;

        $pendingRequests = (int)DB::table('mobile_friendships')
            ->where('status', 'pending')
            ->where(function ($q) use ($meMobileUserId) {
                $q->where('mobile_user_id', $meMobileUserId)
                    ->orWhere('friend_mobile_user_id', $meMobileUserId);
            })
            ->where('requested_by_mobile_user_id', '!=', $meMobileUserId)
            ->count();

        $friendRows = DB::table('mobile_friendships')
            ->where('status', 'accepted')
            ->where(function ($q) use ($meMobileUserId) {
                $q->where('mobile_user_id', $meMobileUserId)
                    ->orWhere('friend_mobile_user_id', $meMobileUserId);
            })
            ->get(['mobile_user_id', 'friend_mobile_user_id']);

        $friendMobileIds = [];
        foreach ($friendRows as $row) {
            $a = (int)($row->mobile_user_id ?? 0);
            $b = (int)($row->friend_mobile_user_id ?? 0);
            $friendId = $a === $meMobileUserId ? $b : $a;
            if ($friendId > 0 && $friendId !== $meMobileUserId) {
                $friendMobileIds[$friendId] = true;
            }
        }

        if (empty($friendMobileIds)) {
            return [
                'online_total' => 0,
                'friends_online' => 0,
                'pending_requests' => $pendingRequests,
                'items' => [],
            ];
        }

        $friendUsers = MobileUser::query()
            ->whereIn('id', array_keys($friendMobileIds))
            ->get(['id', 'login'])
            ->keyBy('id');

        $friendLogins = $friendUsers
            ->pluck('login')
            ->filter(fn($x) => is_string($x) && trim($x) !== '')
            ->map(fn($x) => trim((string)$x))
            ->values()
            ->all();

        if (empty($friendLogins)) {
            return [
                'online_total' => 0,
                'friends_online' => 0,
                'pending_requests' => $pendingRequests,
                'items' => [],
            ];
        }

        $friendClients = Client::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('login', $friendLogins)
            ->get(['id', 'login']);

        if ($friendClients->isEmpty()) {
            return [
                'online_total' => 0,
                'friends_online' => 0,
                'pending_requests' => $pendingRequests,
                'items' => [],
            ];
        }

        $clientIdToLogin = [];
        foreach ($friendClients as $fc) {
            $clientIdToLogin[(int)$fc->id] = (string)$fc->login;
        }

        $activeRows = DB::table('sessions as s')
            ->join('clients as c', function ($join) use ($tenantId) {
                $join->on('c.id', '=', 's.client_id')
                    ->where('c.tenant_id', '=', $tenantId);
            })
            ->leftJoin('pcs as p', function ($join) use ($tenantId) {
                $join->on('p.id', '=', 's.pc_id')
                    ->where('p.tenant_id', '=', $tenantId);
            })
            ->leftJoin('zones as z', function ($join) use ($tenantId) {
                $join->on('z.id', '=', 'p.zone_id')
                    ->where('z.tenant_id', '=', $tenantId);
            })
            ->where('s.tenant_id', $tenantId)
            ->where('s.status', 'active')
            ->whereIn('s.client_id', array_keys($clientIdToLogin))
            ->orderByDesc('s.started_at')
            ->limit(30)
            ->get([
                's.client_id',
                's.started_at',
                'p.code as pc_code',
                'z.name as zone_name',
                'p.zone as zone_legacy',
            ]);

        $items = [];
        foreach ($activeRows as $row) {
            $cid = (int)($row->client_id ?? 0);
            if ($cid <= 0) {
                continue;
            }

            $login = (string)($clientIdToLogin[$cid] ?? '');
            if ($login === '') {
                continue;
            }

            $startedAt = $row->started_at ? Carbon::parse((string)$row->started_at) : now();
            $elapsedMin = max(1, (int)$startedAt->diffInMinutes(now()));
            $zoneName = trim((string)($row->zone_name ?? $row->zone_legacy ?? ''));

            $items[] = [
                'client_id' => $cid,
                'login' => $login,
                'is_friend' => true,
                'pc_name' => trim((string)($row->pc_code ?? '')) ?: ('PC #' . ($row->pc_id ?? '-')),
                'zone_name' => $zoneName,
                'elapsed_min' => $elapsedMin,
            ];
        }

        return [
            'online_total' => count($items),
            'friends_online' => count($items),
            'pending_requests' => $pendingRequests,
            'items' => $items,
        ];
    }

    private function buildBundleHints(int $tenantId): array
    {
        $zones = DB::table('zones')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get(['id', 'name', 'price_per_hour']);

        $zoneRateById = [];
        $zoneRateByName = [];
        $fallbackRate = 0;
        foreach ($zones as $z) {
            $price = (int)($z->price_per_hour ?? 0);
            if ($price <= 0) {
                continue;
            }
            $zoneRateById[(int)$z->id] = $price;
            $zoneRateByName[strtolower(trim((string)$z->name))] = $price;
            if ($fallbackRate === 0) {
                $fallbackRate = $price;
            } else {
                $fallbackRate = (int)round(($fallbackRate + $price) / 2);
            }
        }

        $items = [];

        $packages = Package::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('duration_min')
            ->orderBy('price')
            ->limit(50)
            ->get(['id', 'name', 'duration_min', 'price', 'zone']);

        foreach ($packages as $p) {
            $durationMin = (int)$p->duration_min;
            $price = (int)$p->price;
            if ($durationMin <= 0 || $price <= 0) {
                continue;
            }

            $zoneNameKey = strtolower(trim((string)$p->zone));
            $zoneRate = $zoneRateByName[$zoneNameKey] ?? $fallbackRate;
            if ($zoneRate <= 0) {
                continue;
            }

            $effectivePerHour = (int)round(($price * 60) / $durationMin);
            $savePerHour = max(0, $zoneRate - $effectivePerHour);
            $savePercent = $zoneRate > 0 ? (int)round(($savePerHour * 100) / $zoneRate) : 0;
            if ($savePerHour <= 0) {
                continue;
            }

            $items[] = [
                'type' => 'package',
                'id' => (int)$p->id,
                'name' => (string)$p->name,
                'zone' => (string)$p->zone,
                'effective_per_hour' => $effectivePerHour,
                'save_per_hour' => $savePerHour,
                'save_percent' => $savePercent,
                'price' => $price,
                'duration_min' => $durationMin,
            ];
        }

        $plans = SubscriptionPlan::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('duration_days')
            ->orderBy('price')
            ->limit(20)
            ->get(['id', 'name', 'zone_id', 'duration_days', 'price']);

        foreach ($plans as $plan) {
            $price = (int)$plan->price;
            $zoneRate = $zoneRateById[(int)$plan->zone_id] ?? $fallbackRate;
            if ($price <= 0 || $zoneRate <= 0) {
                continue;
            }
            // Assume 2 hours/day usage for monthly-like comparison.
            $hoursBase = max(1, (int)$plan->duration_days) * 2;
            $effectivePerHour = (int)round($price / $hoursBase);
            $savePerHour = max(0, $zoneRate - $effectivePerHour);
            $savePercent = $zoneRate > 0 ? (int)round(($savePerHour * 100) / $zoneRate) : 0;
            if ($savePerHour <= 0) {
                continue;
            }

            $items[] = [
                'type' => 'subscription',
                'id' => (int)$plan->id,
                'name' => (string)$plan->name,
                'zone_id' => (int)$plan->zone_id,
                'effective_per_hour' => $effectivePerHour,
                'save_per_hour' => $savePerHour,
                'save_percent' => $savePercent,
                'price' => $price,
                'duration_days' => (int)$plan->duration_days,
            ];
        }

        usort($items, function ($a, $b) {
            $p = (int)($b['save_percent'] ?? 0) <=> (int)($a['save_percent'] ?? 0);
            if ($p !== 0) {
                return $p;
            }
            return (int)($b['save_per_hour'] ?? 0) <=> (int)($a['save_per_hour'] ?? 0);
        });

        return [
            'items' => array_slice($items, 0, 3),
        ];
    }

    private function buildSmartQueue(int $tenantId, int $clientId): array
    {
        if (!Schema::hasTable('mobile_smart_queue')) {
            return [
                'count' => 0,
                'items' => [],
                'notifications' => [],
            ];
        }

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
            ]);

        $items = [];
        $notifications = [];

        foreach ($rows as $row) {
            $zoneKey = $this->normalizeZoneKey($row->zone_key ? (string)$row->zone_key : null);

            $positionQ = DB::table('mobile_smart_queue')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['waiting', 'notified'])
                ->where('id', '<=', (int)$row->id);
            if ($zoneKey === null) {
                $positionQ->whereNull('zone_key');
            } else {
                $positionQ->where('zone_key', $zoneKey);
            }
            $position = (int)$positionQ->count();

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
                'notified_at' => $notifiedAt ? $notifiedAt->toIso8601String() : null,
            ];
        }

        return [
            'count' => count($items),
            'items' => $items,
            'notifications' => $notifications,
        ];
    }

    private function buildSessionHighlights(int $tenantId, int $clientId): array
    {
        $now = now();
        $from = $now->copy()->subDays(30);

        $rows = DB::table('sessions as s')
            ->leftJoin('pcs as p', function ($join) use ($tenantId) {
                $join->on('p.id', '=', 's.pc_id')->where('p.tenant_id', '=', $tenantId);
            })
            ->leftJoin('zones as z', function ($join) use ($tenantId) {
                $join->on('z.id', '=', 'p.zone_id')->where('z.tenant_id', '=', $tenantId);
            })
            ->where('s.tenant_id', $tenantId)
            ->where('s.client_id', $clientId)
            ->where(function ($q) use ($from) {
                $q->where('s.started_at', '>=', $from)
                    ->orWhere('s.ended_at', '>=', $from)
                    ->orWhere(function ($q2) {
                        $q2->where('s.status', 'active')->whereNull('s.ended_at');
                    });
            })
            ->orderByDesc('s.started_at')
            ->limit(400)
            ->get([
                's.started_at',
                's.ended_at',
                's.status',
                'z.name as zone_name',
                'p.zone as zone_legacy',
            ]);

        $sessionCount = 0;
        $totalMinutes = 0;
        $playDays = [];
        $hourCounter = [];
        $zoneCounter = [];
        $lastSessionAt = null;

        foreach ($rows as $row) {
            $startedAt = $row->started_at ? Carbon::parse((string)$row->started_at) : null;
            if (!$startedAt) {
                continue;
            }

            $endAt = $row->ended_at ? Carbon::parse((string)$row->ended_at) : $now;
            if ($endAt->lessThanOrEqualTo($startedAt)) {
                continue;
            }

            $sessionCount++;
            $duration = max(1, (int)$startedAt->diffInMinutes($endAt));
            $totalMinutes += $duration;
            $dayKey = $startedAt->format('Y-m-d');
            $playDays[$dayKey] = (int)($playDays[$dayKey] ?? 0) + $duration;
            $hour = (int)$startedAt->hour;
            $hourCounter[$hour] = (int)($hourCounter[$hour] ?? 0) + $duration;

            $zoneName = trim((string)($row->zone_name ?? $row->zone_legacy ?? ''));
            if ($zoneName === '') {
                $zoneName = 'Default';
            }
            $zoneCounter[$zoneName] = (int)($zoneCounter[$zoneName] ?? 0) + $duration;

            if ($lastSessionAt === null || $startedAt->greaterThan($lastSessionAt)) {
                $lastSessionAt = $startedAt;
            }
        }

        $avgSessionMin = $sessionCount > 0 ? (int)round($totalMinutes / $sessionCount) : 0;

        $peakHour = null;
        if (!empty($hourCounter)) {
            arsort($hourCounter);
            $hour = (int)array_key_first($hourCounter);
            $peakHour = [
                'hour' => $hour,
                'label' => sprintf('%02d:00-%02d:00', $hour, ($hour + 1) % 24),
                'minutes' => (int)($hourCounter[$hour] ?? 0),
            ];
        }

        $favoriteZone = null;
        if (!empty($zoneCounter)) {
            arsort($zoneCounter);
            $zoneName = (string)array_key_first($zoneCounter);
            $favoriteZone = [
                'name' => $zoneName,
                'minutes' => (int)($zoneCounter[$zoneName] ?? 0),
            ];
        }

        $streak = 0;
        $cursor = $now->copy()->startOfDay();
        for ($i = 0; $i < 31; $i++) {
            $key = $cursor->format('Y-m-d');
            if ((int)($playDays[$key] ?? 0) > 0) {
                $streak++;
                $cursor->subDay();
                continue;
            }
            break;
        }

        return [
            'period_days' => 30,
            'session_count' => $sessionCount,
            'total_minutes' => $totalMinutes,
            'avg_session_min' => $avgSessionMin,
            'play_days' => count($playDays),
            'active_streak_days' => $streak,
            'peak_hour' => $peakHour,
            'favorite_zone' => $favoriteZone,
            'last_session_at' => $lastSessionAt ? $lastSessionAt->toIso8601String() : null,
        ];
    }

    private function normalizeZoneKey(?string $zoneKey): ?string
    {
        $raw = trim((string)$zoneKey);
        return $raw === '' ? null : strtolower($raw);
    }

    private function queueZoneSnapshot(int $tenantId, ?string $zoneKey): array
    {
        $now = now();
        $pcs = Pc::query()
            ->where('tenant_id', $tenantId)
            ->with(['zoneRel:id,name'])
            ->get(['id', 'zone_id', 'zone']);

        $pcMeta = [];
        foreach ($pcs as $pc) {
            $currentZoneKey = ((int)($pc->zone_id ?? 0) > 0)
                ? 'id:' . (int)$pc->zone_id
                : 'name:' . strtolower(trim((string)($pc->zoneRel?->name ?? $pc->zone ?? 'default')));
            if ($zoneKey !== null && $currentZoneKey !== $zoneKey) {
                continue;
            }
            $zoneName = trim((string)($pc->zoneRel?->name ?? $pc->zone ?? 'Default'));
            if ($zoneName === '') {
                $zoneName = 'Default';
            }
            $pcMeta[(int)$pc->id] = [
                'zone_key' => $currentZoneKey,
                'zone_name' => $zoneName,
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
        $busySet = [];
        foreach ($busyRows as $row) {
            $busySet[(int)$row->pc_id] = $row;
        }

        $bookings = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('pc_id', $pcIds)
            ->where('reserved_until', '>', $now)
            ->get(['pc_id', 'reserved_until']);
        $bookingSet = [];
        foreach ($bookings as $row) {
            $bookingSet[(int)$row->pc_id] = $row;
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
                'zone_name' => (string)$zoneName,
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
        foreach ($bookings as $row) {
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

    private function eventPayload($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
