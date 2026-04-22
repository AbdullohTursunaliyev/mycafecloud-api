<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\MobileUser;
use App\Models\Package;
use App\Models\Pc;
use App\Models\Session;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MobileClientSummaryService
{
    public function __construct(
        private readonly MobileQueueService $queues,
        private readonly MobileMissionService $missions,
    ) {
    }

    public function buildSummary(int $tenantId, int $clientId): array
    {
        $client = Client::query()->where('tenant_id', $tenantId)->findOrFail($clientId);

        $totalTopup = (int) ClientTransaction::query()
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
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();

        $activeSubscription = DB::table('client_subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();

        $activity = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'type', 'amount', 'bonus_amount', 'payment_method', 'created_at'])
            ->map(function ($transaction) {
                $type = (string) $transaction->type;
                $title = match ($type) {
                    'topup' => 'Topup',
                    'package' => 'Package purchase',
                    'subscription' => 'Subscription',
                    'tier_bonus' => 'Rank bonus',
                    'mission_bonus' => 'Mission reward',
                    default => $type,
                };

                return [
                    'id' => (int) $transaction->id,
                    'type' => $type,
                    'title' => $title,
                    'amount' => (int) $transaction->amount,
                    'bonus_amount' => (int) ($transaction->bonus_amount ?? 0),
                    'payment_method' => (string) ($transaction->payment_method ?? ''),
                    'created_at' => (string) $transaction->created_at,
                ];
            })
            ->values()
            ->all();

        return [
            'client' => [
                'id' => (int) $client->id,
                'login' => (string) $client->login,
                'balance' => (int) $client->balance,
                'bonus' => (int) $client->bonus,
            ],
            'rank' => $rank,
            'leaderboard' => $leaderboard,
            'active' => [
                'package' => $activePackage ? [
                    'package_id' => (int) $activePackage->package_id,
                    'remaining_min' => (int) $activePackage->remaining_min,
                    'expires_at' => $activePackage->expires_at ? (string) $activePackage->expires_at : null,
                ] : null,
                'subscription' => $activeSubscription ? [
                    'subscription_id' => (int) $activeSubscription->id,
                    'plan_id' => (int) ($activeSubscription->plan_id ?? 0),
                    'ends_at' => $activeSubscription->ends_at ? (string) $activeSubscription->ends_at : null,
                    'status' => (string) ($activeSubscription->status ?? 'active'),
                ] : null,
            ],
            'activity' => $activity,
            'missions' => $this->missions->build($tenantId, $clientId),
            'radar' => $this->buildFriendRadar($tenantId, $clientId),
            'bundle_hints' => $this->buildBundleHints($tenantId),
            'smart_queue' => $this->queues->listForClient($tenantId, $clientId),
            'session_highlights' => $this->buildSessionHighlights($tenantId, $clientId),
        ];
    }

    public function claimMission(int $tenantId, int $clientId, string $code): array
    {
        return $this->missions->claim($tenantId, $clientId, $code);
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

        $totalPlayers = (int) $rows->count();
        $myPosition = null;
        foreach ($rows as $index => $row) {
            if ((int) $row->id === $clientId) {
                $myPosition = $index + 1;
                break;
            }
        }

        $top = $rows->take(10)->values()->map(function ($row, int $index) {
            $topup = (int) ($row->total_topup ?? 0);
            $rank = ClientRankService::byTotalTopup($topup);
            $current = is_array($rank['current'] ?? null) ? $rank['current'] : [];
            return [
                'position' => $index + 1,
                'client_id' => (int) $row->id,
                'login' => (string) ($row->login ?? ('#' . $row->id)),
                'total_topup' => $topup,
                'rank' => [
                    'key' => (string) ($current['key'] ?? ''),
                    'name' => (string) ($current['name'] ?? ''),
                    'color' => (string) ($current['color'] ?? ''),
                    'icon' => (string) ($current['icon'] ?? ''),
                ],
            ];
        })->all();

        return [
            'position' => $myPosition,
            'total_players' => $totalPlayers,
            'top' => $top,
        ];
    }

    private function buildFriendRadar(int $tenantId, int $clientId): array
    {
        $client = Client::query()->where('tenant_id', $tenantId)->find($clientId, ['id', 'login']);
        if (!$client) {
            return ['online_total' => 0, 'friends_online' => 0, 'pending_requests' => 0, 'items' => []];
        }

        $meMobileUser = MobileUser::query()->where('login', (string) $client->login)->first(['id', 'login']);
        if (!$meMobileUser) {
            return ['online_total' => 0, 'friends_online' => 0, 'pending_requests' => 0, 'items' => []];
        }

        $meMobileUserId = (int) $meMobileUser->id;
        $pendingRequests = (int) DB::table('mobile_friendships')
            ->where('status', 'pending')
            ->where(function ($query) use ($meMobileUserId) {
                $query->where('mobile_user_id', $meMobileUserId)
                    ->orWhere('friend_mobile_user_id', $meMobileUserId);
            })
            ->where('requested_by_mobile_user_id', '!=', $meMobileUserId)
            ->count();

        $friendRows = DB::table('mobile_friendships')
            ->where('status', 'accepted')
            ->where(function ($query) use ($meMobileUserId) {
                $query->where('mobile_user_id', $meMobileUserId)
                    ->orWhere('friend_mobile_user_id', $meMobileUserId);
            })
            ->get(['mobile_user_id', 'friend_mobile_user_id']);

        $friendMobileIds = [];
        foreach ($friendRows as $row) {
            $a = (int) ($row->mobile_user_id ?? 0);
            $b = (int) ($row->friend_mobile_user_id ?? 0);
            $friendId = $a === $meMobileUserId ? $b : $a;
            if ($friendId > 0 && $friendId !== $meMobileUserId) {
                $friendMobileIds[$friendId] = true;
            }
        }

        if (empty($friendMobileIds)) {
            return ['online_total' => 0, 'friends_online' => 0, 'pending_requests' => $pendingRequests, 'items' => []];
        }

        $friendUsers = MobileUser::query()
            ->whereIn('id', array_keys($friendMobileIds))
            ->get(['id', 'login'])
            ->keyBy('id');

        $friendLogins = $friendUsers->pluck('login')
            ->filter(static fn($login) => is_string($login) && trim($login) !== '')
            ->map(static fn($login) => trim((string) $login))
            ->values()
            ->all();

        if (empty($friendLogins)) {
            return ['online_total' => 0, 'friends_online' => 0, 'pending_requests' => $pendingRequests, 'items' => []];
        }

        $friendClients = Client::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('login', $friendLogins)
            ->get(['id', 'login']);

        if ($friendClients->isEmpty()) {
            return ['online_total' => 0, 'friends_online' => 0, 'pending_requests' => $pendingRequests, 'items' => []];
        }

        $clientIdToLogin = [];
        foreach ($friendClients as $friendClient) {
            $clientIdToLogin[(int) $friendClient->id] = (string) $friendClient->login;
        }

        $activeRows = DB::table('sessions as s')
            ->join('clients as c', function ($join) use ($tenantId) {
                $join->on('c.id', '=', 's.client_id')->where('c.tenant_id', '=', $tenantId);
            })
            ->leftJoin('pcs as p', function ($join) use ($tenantId) {
                $join->on('p.id', '=', 's.pc_id')->where('p.tenant_id', '=', $tenantId);
            })
            ->leftJoin('zones as z', function ($join) use ($tenantId) {
                $join->on('z.id', '=', 'p.zone_id')->where('z.tenant_id', '=', $tenantId);
            })
            ->where('s.tenant_id', $tenantId)
            ->where('s.status', 'active')
            ->whereIn('s.client_id', array_keys($clientIdToLogin))
            ->orderByDesc('s.started_at')
            ->limit(30)
            ->get(['s.client_id', 's.started_at', 'p.code as pc_code', 'z.name as zone_name', 'p.zone as zone_legacy']);

        $items = [];
        foreach ($activeRows as $row) {
            $cid = (int) ($row->client_id ?? 0);
            if ($cid <= 0) {
                continue;
            }

            $login = (string) ($clientIdToLogin[$cid] ?? '');
            if ($login === '') {
                continue;
            }

            $startedAt = $row->started_at ? Carbon::parse((string) $row->started_at) : now();
            $elapsedMin = max(1, (int) $startedAt->diffInMinutes(now()));
            $zoneName = trim((string) ($row->zone_name ?? $row->zone_legacy ?? ''));

            $items[] = [
                'client_id' => $cid,
                'login' => $login,
                'is_friend' => true,
                'pc_name' => trim((string) ($row->pc_code ?? '')) ?: ('PC #' . ($row->pc_id ?? '-')),
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
        foreach ($zones as $zone) {
            $price = (int) ($zone->price_per_hour ?? 0);
            if ($price <= 0) {
                continue;
            }

            $zoneRateById[(int) $zone->id] = $price;
            $zoneRateByName[strtolower(trim((string) $zone->name))] = $price;
            $fallbackRate = $fallbackRate === 0 ? $price : (int) round(($fallbackRate + $price) / 2);
        }

        $items = [];
        foreach (Package::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderByDesc('duration_min')->orderBy('price')->limit(50)->get(['id', 'name', 'duration_min', 'price', 'zone']) as $package) {
            $durationMin = (int) $package->duration_min;
            $price = (int) $package->price;
            if ($durationMin <= 0 || $price <= 0) {
                continue;
            }

            $zoneNameKey = strtolower(trim((string) $package->zone));
            $zoneRate = $zoneRateByName[$zoneNameKey] ?? $fallbackRate;
            if ($zoneRate <= 0) {
                continue;
            }

            $effectivePerHour = (int) round(($price * 60) / $durationMin);
            $savePerHour = max(0, $zoneRate - $effectivePerHour);
            $savePercent = $zoneRate > 0 ? (int) round(($savePerHour * 100) / $zoneRate) : 0;
            if ($savePerHour <= 0) {
                continue;
            }

            $items[] = [
                'type' => 'package',
                'id' => (int) $package->id,
                'name' => (string) $package->name,
                'zone' => (string) $package->zone,
                'effective_per_hour' => $effectivePerHour,
                'save_per_hour' => $savePerHour,
                'save_percent' => $savePercent,
                'price' => $price,
                'duration_min' => $durationMin,
            ];
        }

        foreach (SubscriptionPlan::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderByDesc('duration_days')->orderBy('price')->limit(20)->get(['id', 'name', 'zone_id', 'duration_days', 'price']) as $plan) {
            $price = (int) $plan->price;
            $zoneRate = $zoneRateById[(int) $plan->zone_id] ?? $fallbackRate;
            if ($price <= 0 || $zoneRate <= 0) {
                continue;
            }

            $hoursBase = max(1, (int) $plan->duration_days) * 2;
            $effectivePerHour = (int) round($price / $hoursBase);
            $savePerHour = max(0, $zoneRate - $effectivePerHour);
            $savePercent = $zoneRate > 0 ? (int) round(($savePerHour * 100) / $zoneRate) : 0;
            if ($savePerHour <= 0) {
                continue;
            }

            $items[] = [
                'type' => 'subscription',
                'id' => (int) $plan->id,
                'name' => (string) $plan->name,
                'zone_id' => (int) $plan->zone_id,
                'effective_per_hour' => $effectivePerHour,
                'save_per_hour' => $savePerHour,
                'save_percent' => $savePercent,
                'price' => $price,
                'duration_days' => (int) $plan->duration_days,
            ];
        }

        usort($items, static function ($a, $b) {
            $percentCompare = (int) ($b['save_percent'] ?? 0) <=> (int) ($a['save_percent'] ?? 0);
            if ($percentCompare !== 0) {
                return $percentCompare;
            }

            return (int) ($b['save_per_hour'] ?? 0) <=> (int) ($a['save_per_hour'] ?? 0);
        });

        return ['items' => array_slice($items, 0, 3)];
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
            ->where(function ($query) use ($from) {
                $query->where('s.started_at', '>=', $from)
                    ->orWhere('s.ended_at', '>=', $from)
                    ->orWhere(function ($inner) {
                        $inner->where('s.status', 'active')->whereNull('s.ended_at');
                    });
            })
            ->orderByDesc('s.started_at')
            ->limit(400)
            ->get(['s.started_at', 's.ended_at', 's.status', 'z.name as zone_name', 'p.zone as zone_legacy']);

        $sessionCount = 0;
        $totalMinutes = 0;
        $playDays = [];
        $hourCounter = [];
        $zoneCounter = [];
        $lastSessionAt = null;

        foreach ($rows as $row) {
            $startedAt = $row->started_at ? Carbon::parse((string) $row->started_at) : null;
            if (!$startedAt) {
                continue;
            }

            $endAt = $row->ended_at ? Carbon::parse((string) $row->ended_at) : $now;
            if ($endAt->lessThanOrEqualTo($startedAt)) {
                continue;
            }

            $sessionCount++;
            $duration = max(1, (int) $startedAt->diffInMinutes($endAt));
            $totalMinutes += $duration;
            $playDays[$startedAt->format('Y-m-d')] = (int) ($playDays[$startedAt->format('Y-m-d')] ?? 0) + $duration;
            $hour = (int) $startedAt->hour;
            $hourCounter[$hour] = (int) ($hourCounter[$hour] ?? 0) + $duration;

            $zoneName = trim((string) ($row->zone_name ?? $row->zone_legacy ?? ''));
            if ($zoneName === '') {
                $zoneName = 'Default';
            }
            $zoneCounter[$zoneName] = (int) ($zoneCounter[$zoneName] ?? 0) + $duration;

            if ($lastSessionAt === null || $startedAt->greaterThan($lastSessionAt)) {
                $lastSessionAt = $startedAt;
            }
        }

        $avgSessionMin = $sessionCount > 0 ? (int) round($totalMinutes / $sessionCount) : 0;
        $peakHour = null;
        if (!empty($hourCounter)) {
            arsort($hourCounter);
            $hour = (int) array_key_first($hourCounter);
            $peakHour = [
                'hour' => $hour,
                'label' => sprintf('%02d:00-%02d:00', $hour, ($hour + 1) % 24),
                'minutes' => (int) ($hourCounter[$hour] ?? 0),
            ];
        }

        $favoriteZone = null;
        if (!empty($zoneCounter)) {
            arsort($zoneCounter);
            $zoneName = (string) array_key_first($zoneCounter);
            $favoriteZone = [
                'name' => $zoneName,
                'minutes' => (int) ($zoneCounter[$zoneName] ?? 0),
            ];
        }

        $streak = 0;
        $cursor = $now->copy()->startOfDay();
        for ($i = 0; $i < 31; $i++) {
            $key = $cursor->format('Y-m-d');
            if ((int) ($playDays[$key] ?? 0) > 0) {
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

}
