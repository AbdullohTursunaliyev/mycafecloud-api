<?php

namespace App\Services;

use App\Models\ClientMembership;
use App\Models\Pc;
use App\Models\Session;
use App\Models\Setting;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExchangeNetworkService
{
    private const DEFAULT_CONFIG = [
        'enabled' => false,
        'radius_km' => 20,
        'min_free_pcs' => 2,
        'referral_bonus_uzs' => 12000,
        'overflow_enabled' => true,
        'auction_floor_uzs' => 6000,
        'auction_ceiling_uzs' => 26000,
    ];

    public function __construct(
        private readonly TenantReportService $reports,
        private readonly TenantSettingService $settings,
        private readonly EventLogger $events,
    ) {}

    public function buildDashboard(int $tenantId, Carbon $from, Carbon $to): array
    {
        $report = $this->reports->build($tenantId, $from, $to);
        $tenant = Tenant::query()->find($tenantId, ['id', 'name', 'status']);
        $config = $this->loadConfig($tenantId);
        $selfSettings = Setting::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('key', ['club_name', 'club_location'])
            ->get()
            ->keyBy('key');

        $selfClubName = (string) (
            optional($selfSettings->get('club_name'))->value
            ?: ($tenant->name ?? ('Club #' . $tenantId))
        );
        $selfLocation = $this->parseClubLocation(optional($selfSettings->get('club_location'))->value);
        $selfActivity = (array) ($report['activity'] ?? []);
        $selfOnline = max(0, (int) ($selfActivity['pcs_online'] ?? 0));
        $selfActive = max(0, (int) ($selfActivity['active_sessions_now'] ?? 0));
        $selfFree = max(0, $selfOnline - $selfActive);
        $selfLoad = $selfOnline > 0 ? round(($selfActive / max(1, $selfOnline)) * 100, 1) : 0.0;

        $partnerTenants = Tenant::query()
            ->where('id', '!=', $tenantId)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('license_keys')
                    ->whereColumn('license_keys.tenant_id', 'tenants.id')
                    ->where('license_keys.status', 'active')
                    ->where(function ($qq) {
                        $qq->whereNull('license_keys.expires_at')
                            ->orWhere('license_keys.expires_at', '>', now());
                    });
            })
            ->orderBy('id')
            ->limit(60)
            ->get(['id', 'name', 'status']);

        $partnerIds = $partnerTenants->pluck('id')->all();
        $partnerSettings = Setting::query()
            ->whereIn('tenant_id', $partnerIds)
            ->whereIn('key', ['club_name', 'club_location', 'exchange_network'])
            ->get()
            ->groupBy('tenant_id');

        $onlineSince = now()->subMinutes((int) config('domain.pc.online_window_minutes', 3));
        $partnerPcRows = Pc::query()
            ->whereIn('tenant_id', $partnerIds)
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            })
            ->selectRaw(
                'tenant_id, COUNT(*) as pcs_total, SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) as pcs_online',
                [$onlineSince],
            )
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $partnerSessionRows = Session::query()
            ->whereIn('tenant_id', $partnerIds)
            ->where('status', 'active')
            ->selectRaw('tenant_id, COUNT(*) as active_sessions')
            ->groupBy('tenant_id')
            ->pluck('active_sessions', 'tenant_id');

        $partners = [];
        $disabledCandidates = 0;

        foreach ($partnerTenants as $partnerTenant) {
            $partnerId = (int) $partnerTenant->id;
            $setMap = collect($partnerSettings->get($partnerId, []))->keyBy('key');
            $partnerConfig = $this->normalizeConfig(
                (array) (optional($setMap->get('exchange_network'))->value ?? self::DEFAULT_CONFIG),
            );
            $partnerClubName = (string) (optional($setMap->get('club_name'))->value ?: $partnerTenant->name);
            $partnerLocation = $this->parseClubLocation(optional($setMap->get('club_location'))->value);

            $pc = $partnerPcRows->get($partnerId);
            $pcsTotal = max(0, (int) ($pc->pcs_total ?? 0));
            $pcsOnline = max(0, (int) ($pc->pcs_online ?? 0));
            $activeSessions = max(0, (int) ($partnerSessionRows[$partnerId] ?? 0));
            $freePcs = max(0, $pcsOnline - $activeSessions);
            $loadPct = $pcsOnline > 0 ? round(($activeSessions / max(1, $pcsOnline)) * 100, 1) : 0.0;

            $distanceKm = null;
            if ($selfLocation && $partnerLocation) {
                $distanceKm = $this->distanceKm(
                    (float) $selfLocation['lat'],
                    (float) $selfLocation['lng'],
                    (float) $partnerLocation['lat'],
                    (float) $partnerLocation['lng'],
                );
            }

            $withinRadius = $distanceKm === null || $distanceKm <= (float) $config['radius_km'];
            $canReceive = (bool) $partnerConfig['enabled']
                && $withinRadius
                && $freePcs >= max(1, (int) $partnerConfig['min_free_pcs']);

            if (!(bool) $partnerConfig['enabled']) {
                $disabledCandidates++;
            }

            $score = ($freePcs * 9) + (100 - $loadPct);
            if ($distanceKm !== null) {
                $score -= $distanceKm * 1.3;
            }
            if (!$canReceive) {
                $score -= 24;
            }

            $partners[] = [
                'tenant_id' => $partnerId,
                'club_name' => $partnerClubName,
                'status' => (string) $partnerTenant->status,
                'exchange_enabled' => (bool) $partnerConfig['enabled'],
                'distance_km' => $distanceKm === null ? null : round($distanceKm, 1),
                'within_radius' => $withinRadius,
                'pcs_total' => $pcsTotal,
                'pcs_online' => $pcsOnline,
                'active_sessions' => $activeSessions,
                'free_pcs' => $freePcs,
                'load_pct' => $loadPct,
                'can_receive' => $canReceive,
                'score' => round($score, 1),
                'suggested_bid_uzs' => $this->clampInt(
                    (int) round(
                        (int) $config['auction_floor_uzs']
                        + (max(0, 45 - (int) $loadPct) * 260),
                    ),
                    (int) $config['auction_floor_uzs'],
                    (int) $config['auction_ceiling_uzs'],
                ),
            ];
        }

        $partners = collect($partners)
            ->sortByDesc(static function ($row) {
                $row = is_array($row) ? $row : [];
                $base = (float) ($row['score'] ?? 0);
                $bonus = !empty($row['can_receive']) ? 100000 : 0;

                return $bonus + $base;
            })
            ->values();

        $topTargets = $partners->where('can_receive', true)->take(3)->values();
        $overflowNeed = $selfLoad >= 88 && $selfFree < max(1, (int) $config['min_free_pcs']);
        $overflowDemandPcs = $overflowNeed ? max(1, (int) ceil(max(0, $selfLoad - 82) / 4)) : 0;
        $availableInTargets = (int) $topTargets->sum('free_pcs');
        $reroutePcs = min($overflowDemandPcs, $availableInTargets);

        $summary = (array) ($report['summary'] ?? []);
        $avgRevPerSession = (int) ($summary['sessions_count'] ?? 0) > 0
            ? round(((int) ($summary['net_sales'] ?? 0)) / max(1, (int) $summary['sessions_count']), 1)
            : 0.0;
        $projectedRecoveredMonthly = (int) round($reroutePcs * $avgRevPerSession * 40);

        $identityBase = ClientMembership::query()->where('tenant_id', $tenantId);
        $membersTotal = (int) (clone $identityBase)->distinct('identity_id')->count('identity_id');
        $crossClubMembers = (int) (clone $identityBase)
            ->whereIn('identity_id', function ($q) {
                $q->from('client_memberships')
                    ->select('identity_id')
                    ->groupBy('identity_id')
                    ->havingRaw('COUNT(DISTINCT tenant_id) > 1');
            })
            ->distinct('identity_id')
            ->count('identity_id');

        $auctionBid = $this->clampInt(
            (int) round(
                (int) $config['auction_floor_uzs']
                + (max(0, ((int) $selfLoad - 70)) * 300),
            ),
            (int) $config['auction_floor_uzs'],
            (int) $config['auction_ceiling_uzs'],
        );

        return [
            'generated_at' => now()->toDateTimeString(),
            'club' => [
                'tenant_id' => $tenantId,
                'name' => $selfClubName,
                'load_pct' => $selfLoad,
                'pcs_online' => $selfOnline,
                'active_sessions' => $selfActive,
                'free_pcs' => $selfFree,
            ],
            'config' => $config,
            'passport' => [
                'enabled' => true,
                'members_total' => $membersTotal,
                'cross_club_members' => $crossClubMembers,
                'portability_label' => 'Bitta MyCafe ID bilan hamkor klublarda kirish mumkin',
            ],
            'partners' => $partners->all(),
            'overflow' => [
                'needed' => $overflowNeed,
                'demand_pcs' => $overflowDemandPcs,
                'reroute_pcs' => $reroutePcs,
                'available_in_targets' => $availableInTargets,
                'projected_recovered_monthly' => $projectedRecoveredMonthly,
                'targets' => $topTargets->all(),
            ],
            'auction' => [
                'recommended_bid_uzs' => $auctionBid,
                'floor_uzs' => (int) $config['auction_floor_uzs'],
                'ceiling_uzs' => (int) $config['auction_ceiling_uzs'],
                'reason' => $overflowNeed
                    ? 'Yuqori yuklama uchun inbound trafik bidni oshirish tavsiya etiladi'
                    : 'Normal rejim: o\'rta bid bilan networkdan trafik yig\'ish mumkin',
            ],
            'network' => [
                'partners_total' => (int) $partners->count(),
                'partners_ready' => (int) $partners->where('can_receive', true)->count(),
                'partners_disabled' => $disabledCandidates,
            ],
            'pitch' => [
                'headline' => 'MyCafe Exchange Network',
                'subline' => 'Bitta klub emas, butun tarmoq bo\'ylab mijoz oqimi.',
                'value' => 'Bo\'sh joy bo\'lsa trafik olasiz, to\'liq bo\'lsa trafikni yo\'naltirasiz.',
            ],
        ];
    }

    public function saveConfig(int $tenantId, int $operatorId, array $payload): array
    {
        $current = $this->loadConfig($tenantId);
        $next = array_merge($current, $payload);

        if ((int) $next['auction_floor_uzs'] > (int) $next['auction_ceiling_uzs']) {
            [$next['auction_floor_uzs'], $next['auction_ceiling_uzs']] = [
                (int) $next['auction_ceiling_uzs'],
                (int) $next['auction_floor_uzs'],
            ];
        }

        $next = $this->normalizeConfig($next);
        $this->settings->set($tenantId, 'exchange_network', $next);

        try {
            $this->events->log(
                $tenantId,
                'exchange_config_updated',
                'operator',
                'operator',
                $operatorId,
                ['config' => $next],
            );
        } catch (\Throwable) {
            // no-op
        }

        return [
            'saved' => true,
            'config' => $next,
        ];
    }

    private function loadConfig(int $tenantId): array
    {
        return $this->normalizeConfig(
            (array) $this->settings->get($tenantId, 'exchange_network', self::DEFAULT_CONFIG),
        );
    }

    private function normalizeConfig(array $config): array
    {
        $cfg = array_merge(self::DEFAULT_CONFIG, $config);
        $floor = $this->clampInt((int) ($cfg['auction_floor_uzs'] ?? self::DEFAULT_CONFIG['auction_floor_uzs']), 0, 10000000);
        $ceil = $this->clampInt((int) ($cfg['auction_ceiling_uzs'] ?? self::DEFAULT_CONFIG['auction_ceiling_uzs']), 0, 10000000);
        if ($floor > $ceil) {
            [$floor, $ceil] = [$ceil, $floor];
        }

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? self::DEFAULT_CONFIG['enabled']),
            'radius_km' => $this->clampInt((int) ($cfg['radius_km'] ?? self::DEFAULT_CONFIG['radius_km']), 1, 300),
            'min_free_pcs' => $this->clampInt((int) ($cfg['min_free_pcs'] ?? self::DEFAULT_CONFIG['min_free_pcs']), 0, 1000),
            'referral_bonus_uzs' => $this->clampInt((int) ($cfg['referral_bonus_uzs'] ?? self::DEFAULT_CONFIG['referral_bonus_uzs']), 0, 10000000),
            'overflow_enabled' => (bool) ($cfg['overflow_enabled'] ?? self::DEFAULT_CONFIG['overflow_enabled']),
            'auction_floor_uzs' => $floor,
            'auction_ceiling_uzs' => $ceil,
        ];
    }

    private function parseClubLocation($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $lat = $raw['lat'] ?? null;
        $lng = $raw['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'address' => isset($raw['address']) ? (string) $raw['address'] : null,
        ];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));

        return $earth * $c;
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
