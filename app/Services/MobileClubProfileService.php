<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClubReview;
use App\Models\Package;
use App\Models\Pc;
use App\Models\PcBooking;
use App\Models\Session;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MobileClubProfileService
{
    public function build(int $tenantId, int $clientId): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $client = Client::query()->where('tenant_id', $tenantId)->findOrFail($clientId);

        $settings = Setting::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('key', ['club_name', 'club_logo', 'club_location'])
            ->get(['key', 'value'])
            ->keyBy('key');

        $clubName = $this->settingString($settings, 'club_name') ?: (string) $tenant->name;
        $clubLogo = $this->normalizeAssetUrl($this->settingString($settings, 'club_logo'));
        $clubLocation = $this->settingArray($settings, 'club_location');

        $pcs = Pc::query()
            ->where('tenant_id', $tenantId)
            ->with(['zoneRel:id,name,price_per_hour'])
            ->get(['id', 'zone_id', 'zone', 'code']);

        $pcIds = $pcs->pluck('id')->map(static fn($id) => (int) $id)->all();
        $latestMetricsByPc = [];

        if (!empty($pcIds)) {
            $rows = DB::table('pc_heartbeats as ph')
                ->selectRaw('DISTINCT ON (ph.pc_id) ph.pc_id, ph.metrics, ph.received_at')
                ->where('ph.tenant_id', $tenantId)
                ->whereIn('ph.pc_id', $pcIds)
                ->orderBy('ph.pc_id')
                ->orderByDesc('ph.received_at')
                ->orderByDesc('ph.id')
                ->get();

            foreach ($rows as $row) {
                $metrics = $row->metrics;
                if (!is_array($metrics)) {
                    $metrics = json_decode((string) $metrics, true);
                }

                if (is_array($metrics)) {
                    $latestMetricsByPc[(int) $row->pc_id] = $metrics;
                }
            }
        }

        $busyPcIds = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->distinct('pc_id')
            ->pluck('pc_id')
            ->map(static fn($id) => (int) $id)
            ->all();
        $busySet = array_flip($busyPcIds);

        $bookedPcIds = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('reserved_until', '>', now())
            ->distinct('pc_id')
            ->pluck('pc_id')
            ->map(static fn($id) => (int) $id)
            ->all();
        $bookedSet = array_flip($bookedPcIds);

        $zoneStats = [];
        $zoneIdToKey = [];
        $knownZones = Zone::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price_per_hour']);

        foreach ($knownZones as $zone) {
            $zoneName = trim((string) $zone->name);
            if ($zoneName === '') {
                $zoneName = 'Default';
            }

            $nameKey = 'name:' . $this->normalizeZoneName($zoneName);
            if (!isset($zoneStats[$nameKey])) {
                $zoneStats[$nameKey] = [
                    'key' => $nameKey,
                    'id' => (int) $zone->id,
                    'name' => $zoneName,
                    'price_per_hour' => (int) $zone->price_per_hour,
                    'pcs_total' => 0,
                    'pcs_free' => 0,
                    'pcs_busy' => 0,
                    'pcs_booked' => 0,
                ];
            } else {
                $zoneStats[$nameKey]['price_per_hour'] = max(
                    (int) $zoneStats[$nameKey]['price_per_hour'],
                    (int) $zone->price_per_hour,
                );

                if ((int) $zoneStats[$nameKey]['id'] === 0) {
                    $zoneStats[$nameKey]['id'] = (int) $zone->id;
                }
            }

            $zoneIdToKey[(int) $zone->id] = $nameKey;
        }

        $cpuCounter = [];
        $gpuCounter = [];
        $ramSumMb = 0;
        $ramCount = 0;
        $pcItems = [];

        foreach ($pcs as $pc) {
            $zoneKey = null;
            if ($pc->zone_id && isset($zoneIdToKey[(int) $pc->zone_id])) {
                $zoneKey = $zoneIdToKey[(int) $pc->zone_id];
            }

            if ($zoneKey === null) {
                $fallbackName = trim((string) ($pc->zoneRel?->name ?? $pc->zone ?? 'Default'));
                if ($fallbackName === '') {
                    $fallbackName = 'Default';
                }

                $zoneKey = 'name:' . $this->normalizeZoneName($fallbackName);
                if (!isset($zoneStats[$zoneKey])) {
                    $zoneStats[$zoneKey] = [
                        'key' => $zoneKey,
                        'id' => 0,
                        'name' => $fallbackName,
                        'price_per_hour' => (int) ($pc->zoneRel?->price_per_hour ?? 0),
                        'pcs_total' => 0,
                        'pcs_free' => 0,
                        'pcs_busy' => 0,
                        'pcs_booked' => 0,
                    ];
                }
            }

            $zoneStats[$zoneKey]['pcs_total']++;

            $pcId = (int) $pc->id;
            $status = 'free';
            if (isset($busySet[$pcId])) {
                $zoneStats[$zoneKey]['pcs_busy']++;
                $status = 'busy';
            } elseif (isset($bookedSet[$pcId])) {
                $zoneStats[$zoneKey]['pcs_booked']++;
                $status = 'booked';
            } else {
                $zoneStats[$zoneKey]['pcs_free']++;
            }

            $pcName = trim((string) ($pc->code ?? ''));
            if ($pcName === '') {
                $pcName = 'PC #' . $pcId;
            }

            $pcItems[] = [
                'id' => $pcId,
                'name' => $pcName,
                'status' => $status,
                'zone_key' => $zoneKey,
                'zone_name' => (string) ($zoneStats[$zoneKey]['name'] ?? 'Default'),
            ];

            $metrics = $latestMetricsByPc[$pcId] ?? null;
            if (!is_array($metrics)) {
                continue;
            }

            $cpu = trim((string) ($metrics['cpu_name'] ?? ''));
            if ($cpu !== '') {
                $cpuCounter[$cpu] = (int) ($cpuCounter[$cpu] ?? 0) + 1;
            }

            $gpu = trim((string) ($metrics['gpu_name'] ?? ''));
            if ($gpu !== '') {
                $gpuCounter[$gpu] = (int) ($gpuCounter[$gpu] ?? 0) + 1;
            }

            $ramMb = (int) ($metrics['ram_total_mb'] ?? 0);
            if ($ramMb > 0) {
                $ramSumMb += $ramMb;
                $ramCount++;
            }
        }

        $zones = array_values($zoneStats);
        usort($zones, static fn($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        usort($pcItems, static function ($a, $b) {
            $zoneCompare = strcasecmp((string) ($a['zone_name'] ?? ''), (string) ($b['zone_name'] ?? ''));
            if ($zoneCompare !== 0) {
                return $zoneCompare;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $totalPcs = (int) $pcs->count();
        $busyCount = (int) count($busyPcIds);
        $bookedCount = (int) count($bookedPcIds);
        $freeCount = max(0, $totalPcs - $busyCount - $bookedCount);
        $insights = $this->buildClubInsights($tenantId, $zones, $pcItems, $totalPcs, $freeCount);

        $reviewStats = ClubReview::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw('
                COUNT(*) as total,
                COALESCE(AVG(rating), 0) as avg_rating,
                COALESCE(AVG(COALESCE(atmosphere_rating, rating)), 0) as avg_atmosphere,
                COALESCE(AVG(COALESCE(cleanliness_rating, rating)), 0) as avg_cleanliness,
                COALESCE(AVG(COALESCE(technical_rating, rating)), 0) as avg_technical,
                COALESCE(AVG(COALESCE(peripherals_rating, rating)), 0) as avg_peripherals
            ')
            ->first() ?? (object) [
                'total' => 0,
                'avg_rating' => 0,
                'avg_atmosphere' => 0,
                'avg_cleanliness' => 0,
                'avg_technical' => 0,
                'avg_peripherals' => 0,
            ];

        return [
            'club' => [
                'id' => (int) $tenant->id,
                'name' => $clubName,
                'logo' => $clubLogo,
                'location' => $clubLocation,
                'stats' => [
                    'pcs_total' => $totalPcs,
                    'zones_total' => (int) count($zones),
                    'pcs_busy' => $busyCount,
                    'pcs_booked' => $bookedCount,
                    'pcs_free' => $freeCount,
                ],
                'reviews' => [
                    'count' => (int) ($reviewStats->total ?? 0),
                    'avg_rating' => round((float) ($reviewStats->avg_rating ?? 0), 2),
                    'avg_atmosphere' => round((float) ($reviewStats->avg_atmosphere ?? 0), 2),
                    'avg_cleanliness' => round((float) ($reviewStats->avg_cleanliness ?? 0), 2),
                    'avg_technical' => round((float) ($reviewStats->avg_technical ?? 0), 2),
                    'avg_peripherals' => round((float) ($reviewStats->avg_peripherals ?? 0), 2),
                ],
                'zones' => $zones,
                'hardware' => [
                    'sample_count' => (int) $ramCount,
                    'top_cpu' => $this->topLabel($cpuCounter),
                    'top_gpu' => $this->topLabel($gpuCounter),
                    'avg_ram_gb' => $ramCount > 0 ? round(($ramSumMb / $ramCount) / 1024, 1) : 0,
                ],
                'insights' => $insights,
                'pcs' => $pcItems,
            ],
            'client' => [
                'id' => (int) $client->id,
                'login' => (string) $client->login,
                'balance' => (int) $client->balance,
                'bonus' => (int) $client->bonus,
            ],
        ];
    }

    private function buildClubInsights(int $tenantId, array $zones, array $pcItems, int $totalPcs, int $freeNow): array
    {
        $days = 14;
        $from = now()->copy()->subDays($days);

        $zoneNameByKey = [];
        $zoneHourRaw = [];
        foreach ($zones as $zone) {
            $key = (string) ($zone['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $zoneNameByKey[$key] = (string) ($zone['name'] ?? 'Default');
            $zoneHourRaw[$key] = array_fill(0, 24, 0);
        }

        $pcMetaById = [];
        foreach ($pcItems as $pc) {
            $pcId = (int) ($pc['id'] ?? 0);
            if ($pcId <= 0) {
                continue;
            }

            $zoneKey = (string) ($pc['zone_key'] ?? 'name:default');
            $zoneName = (string) ($pc['zone_name'] ?? 'Default');
            $pcName = (string) ($pc['name'] ?? ('PC #' . $pcId));
            $pcMetaById[$pcId] = [
                'pc_id' => $pcId,
                'pc_name' => $pcName,
                'zone_key' => $zoneKey,
                'zone_name' => $zoneName,
            ];

            if (!isset($zoneHourRaw[$zoneKey])) {
                $zoneHourRaw[$zoneKey] = array_fill(0, 24, 0);
                $zoneNameByKey[$zoneKey] = $zoneName;
            }
        }

        $hourTotals = array_fill(0, 24, 0);
        $pcStarts = [];

        $sessionRows = Session::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('pc_id')
            ->where('started_at', '>=', $from)
            ->orderByDesc('started_at')
            ->limit(30000)
            ->get(['pc_id', 'started_at']);

        foreach ($sessionRows as $row) {
            $pcId = (int) ($row->pc_id ?? 0);
            if ($pcId <= 0) {
                continue;
            }

            $startedAt = $row->started_at ? Carbon::parse((string) $row->started_at) : null;
            if (!$startedAt) {
                continue;
            }

            $hour = (int) $startedAt->hour;
            if ($hour < 0 || $hour > 23) {
                continue;
            }

            $zoneKey = (string) ($pcMetaById[$pcId]['zone_key'] ?? 'name:default');
            if (!isset($zoneHourRaw[$zoneKey])) {
                $zoneHourRaw[$zoneKey] = array_fill(0, 24, 0);
                $zoneNameByKey[$zoneKey] = (string) ($pcMetaById[$pcId]['zone_name'] ?? 'Default');
            }

            $zoneHourRaw[$zoneKey][$hour] = (int) $zoneHourRaw[$zoneKey][$hour] + 1;
            $hourTotals[$hour] = (int) $hourTotals[$hour] + 1;
            $pcStarts[$pcId] = (int) ($pcStarts[$pcId] ?? 0) + 1;
        }

        $heatmap = [];
        foreach ($zoneHourRaw as $zoneKey => $hoursRaw) {
            $max = max($hoursRaw);
            $scores = [];

            foreach ($hoursRaw as $count) {
                $scores[] = $max > 0 ? (int) round(((int) $count * 100) / $max) : 0;
            }

            $heatmap[] = [
                'zone_key' => $zoneKey,
                'zone_name' => (string) ($zoneNameByKey[$zoneKey] ?? 'Default'),
                'hours' => $scores,
            ];
        }

        usort($heatmap, static fn($a, $b) => strcasecmp((string) $a['zone_name'], (string) $b['zone_name']));

        $bestHours = collect(range(0, 23))
            ->map(static function (int $hour) use ($hourTotals) {
                return [
                    'hour' => $hour,
                    'count' => (int) $hourTotals[$hour],
                    'label' => sprintf('%02d:00-%02d:00', $hour, ($hour + 1) % 24),
                ];
            })
            ->sortBy(static function ($row) {
                return sprintf('%08d-%02d', (int) $row['count'], (int) $row['hour']);
            })
            ->take(3)
            ->values()
            ->all();

        arsort($pcStarts);
        $topPcs = [];
        foreach ($pcStarts as $pcId => $count) {
            $pcId = (int) $pcId;
            if ($pcId <= 0 || !isset($pcMetaById[$pcId])) {
                continue;
            }

            $meta = $pcMetaById[$pcId];
            $topPcs[] = [
                'pc_id' => $pcId,
                'pc_name' => (string) $meta['pc_name'],
                'zone_key' => (string) $meta['zone_key'],
                'zone_name' => (string) $meta['zone_name'],
                'sessions_count' => (int) $count,
            ];

            if (count($topPcs) >= 3) {
                break;
            }
        }

        $currentHour = (int) now()->hour;
        $avgLoad = (float) (array_sum($hourTotals) / 24.0);
        $currentLoad = (int) $hourTotals[$currentHour];
        $freeRatio = $totalPcs > 0 ? (int) round(($freeNow * 100) / $totalPcs) : 0;
        $recommendNow = $freeRatio >= 35 && $currentLoad <= $avgLoad;

        $liveQueue = $this->buildLiveQueue($tenantId, $zones, $pcMetaById);
        $smartBundle = $this->buildSmartBundleInsights($tenantId, $zones);

        return [
            'range_days' => $days,
            'best_time' => [
                'recommend_now' => $recommendNow,
                'free_ratio' => $freeRatio,
                'current_hour' => $currentHour,
                'hours' => $bestHours,
            ],
            'live_queue' => $liveQueue,
            'heatmap' => $heatmap,
            'top_pcs' => $topPcs,
            'smart_bundle' => $smartBundle,
        ];
    }

    private function buildLiveQueue(int $tenantId, array $zones, array $pcMetaById): array
    {
        $zoneRows = [];
        $zoneAvgDuration = [];

        foreach ($zones as $zone) {
            $zoneKey = (string) ($zone['key'] ?? '');
            if ($zoneKey === '') {
                continue;
            }

            $zoneRows[$zoneKey] = [
                'zone_key' => $zoneKey,
                'zone_name' => (string) ($zone['name'] ?? 'Default'),
                'free_now' => (int) ($zone['pcs_free'] ?? 0),
                'busy_now' => (int) ($zone['pcs_busy'] ?? 0),
                'booked_now' => (int) ($zone['pcs_booked'] ?? 0),
                'eta_min' => 0,
            ];
        }

        $endedRows = Session::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('pc_id')
            ->whereNotNull('started_at')
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', now()->subDays(21))
            ->get(['pc_id', 'started_at', 'ended_at']);

        $durationSums = [];
        $durationCounts = [];
        foreach ($endedRows as $row) {
            $pcId = (int) ($row->pc_id ?? 0);
            if ($pcId <= 0 || !isset($pcMetaById[$pcId])) {
                continue;
            }

            $zoneKey = (string) ($pcMetaById[$pcId]['zone_key'] ?? '');
            if ($zoneKey === '') {
                continue;
            }

            $start = Carbon::parse((string) $row->started_at);
            $end = Carbon::parse((string) $row->ended_at);
            $duration = max(1, (int) $start->diffInMinutes($end));
            $durationSums[$zoneKey] = (int) ($durationSums[$zoneKey] ?? 0) + $duration;
            $durationCounts[$zoneKey] = (int) ($durationCounts[$zoneKey] ?? 0) + 1;
        }

        foreach ($zoneRows as $zoneKey => $_) {
            $sum = (int) ($durationSums[$zoneKey] ?? 0);
            $count = (int) ($durationCounts[$zoneKey] ?? 0);
            $zoneAvgDuration[$zoneKey] = $count > 0 ? (int) round($sum / $count) : 120;
        }

        $etaByZone = [];

        $activeRows = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereNotNull('pc_id')
            ->whereNotNull('started_at')
            ->get(['pc_id', 'started_at']);

        foreach ($activeRows as $row) {
            $pcId = (int) ($row->pc_id ?? 0);
            if ($pcId <= 0 || !isset($pcMetaById[$pcId])) {
                continue;
            }

            $zoneKey = (string) ($pcMetaById[$pcId]['zone_key'] ?? '');
            if ($zoneKey === '') {
                continue;
            }

            $avg = (int) ($zoneAvgDuration[$zoneKey] ?? 120);
            $elapsed = (int) Carbon::parse((string) $row->started_at)->diffInMinutes(now());
            $remain = max(5, $avg - $elapsed);
            $etaByZone[$zoneKey] = isset($etaByZone[$zoneKey]) ? min($etaByZone[$zoneKey], $remain) : $remain;
        }

        $bookingRows = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('reserved_until', '>', now())
            ->get(['pc_id', 'reserved_until']);

        foreach ($bookingRows as $row) {
            $pcId = (int) ($row->pc_id ?? 0);
            if ($pcId <= 0 || !isset($pcMetaById[$pcId])) {
                continue;
            }

            $zoneKey = (string) ($pcMetaById[$pcId]['zone_key'] ?? '');
            if ($zoneKey === '') {
                continue;
            }

            $remain = max(1, (int) ceil(now()->diffInSeconds(Carbon::parse((string) $row->reserved_until), false) / 60));
            $etaByZone[$zoneKey] = isset($etaByZone[$zoneKey]) ? min($etaByZone[$zoneKey], $remain) : $remain;
        }

        $queue = [];
        foreach ($zoneRows as $zoneKey => $row) {
            $freeNow = (int) $row['free_now'];
            $eta = $freeNow > 0
                ? 0
                : (int) ($etaByZone[$zoneKey] ?? max(5, (int) round(($zoneAvgDuration[$zoneKey] ?? 120) / 2)));
            $row['eta_min'] = $eta;
            $row['avg_session_min'] = (int) ($zoneAvgDuration[$zoneKey] ?? 120);
            $queue[] = $row;
        }

        usort($queue, static function ($a, $b) {
            $freeCompare = (int) $b['free_now'] <=> (int) $a['free_now'];
            if ($freeCompare !== 0) {
                return $freeCompare;
            }

            return (int) $a['eta_min'] <=> (int) $b['eta_min'];
        });

        return $queue;
    }

    private function buildSmartBundleInsights(int $tenantId, array $zones): array
    {
        $zoneById = [];
        $zonePriceByKey = [];
        $fallbackRate = 0;

        foreach ($zones as $zone) {
            $zoneId = (int) ($zone['id'] ?? 0);
            $zoneName = trim((string) ($zone['name'] ?? ''));
            $zoneKey = (string) ($zone['key'] ?? '');
            $price = (int) ($zone['price_per_hour'] ?? 0);

            if ($zoneId > 0) {
                $zoneById[$zoneId] = ['name' => $zoneName, 'price' => $price];
            }

            if ($zoneKey !== '') {
                $zonePriceByKey[$zoneKey] = $price;
            }

            if ($zoneName !== '') {
                $zonePriceByKey['name:' . $this->normalizeZoneName($zoneName)] = $price;
            }

            if ($price > 0) {
                $fallbackRate = $fallbackRate === 0 ? $price : (int) round(($fallbackRate + $price) / 2);
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

        foreach ($packages as $package) {
            $durationMin = (int) $package->duration_min;
            $price = (int) $package->price;
            if ($durationMin <= 0 || $price <= 0) {
                continue;
            }

            $zoneNorm = 'name:' . $this->normalizeZoneName((string) $package->zone);
            $zoneRate = (int) ($zonePriceByKey[$zoneNorm] ?? $fallbackRate);
            if ($zoneRate <= 0) {
                continue;
            }

            $effectivePerHour = (int) round(($price * 60) / $durationMin);
            $savePerHour = max(0, $zoneRate - $effectivePerHour);
            if ($savePerHour <= 0) {
                continue;
            }

            $items[] = [
                'type' => 'package',
                'id' => (int) $package->id,
                'name' => (string) $package->name,
                'zone_name' => (string) $package->zone,
                'price' => $price,
                'duration_min' => $durationMin,
                'effective_per_hour' => $effectivePerHour,
                'save_per_hour' => $savePerHour,
                'save_percent' => (int) round(($savePerHour * 100) / $zoneRate),
            ];
        }

        $plans = SubscriptionPlan::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('duration_days')
            ->orderBy('price')
            ->limit(30)
            ->get(['id', 'name', 'zone_id', 'duration_days', 'price']);

        foreach ($plans as $plan) {
            $price = (int) $plan->price;
            $days = max(1, (int) $plan->duration_days);
            $zoneMeta = $zoneById[(int) $plan->zone_id] ?? null;
            $zoneRate = (int) ($zoneMeta['price'] ?? $fallbackRate);
            if ($price <= 0 || $zoneRate <= 0) {
                continue;
            }

            $effectivePerHour = (int) round($price / ($days * 2));
            $savePerHour = max(0, $zoneRate - $effectivePerHour);
            if ($savePerHour <= 0) {
                continue;
            }

            $items[] = [
                'type' => 'subscription',
                'id' => (int) $plan->id,
                'name' => (string) $plan->name,
                'zone_name' => (string) ($zoneMeta['name'] ?? ''),
                'price' => $price,
                'duration_days' => $days,
                'effective_per_hour' => $effectivePerHour,
                'save_per_hour' => $savePerHour,
                'save_percent' => (int) round(($savePerHour * 100) / $zoneRate),
            ];
        }

        usort($items, static function ($a, $b) {
            $savePercentCompare = (int) ($b['save_percent'] ?? 0) <=> (int) ($a['save_percent'] ?? 0);
            if ($savePercentCompare !== 0) {
                return $savePercentCompare;
            }

            return (int) ($b['save_per_hour'] ?? 0) <=> (int) ($a['save_per_hour'] ?? 0);
        });

        return [
            'items' => array_slice($items, 0, 3),
        ];
    }

    private function topLabel(array $counter): ?string
    {
        if (empty($counter)) {
            return null;
        }

        arsort($counter);
        $top = array_key_first($counter);

        return $top ? (string) $top : null;
    }

    private function normalizeZoneName(string $name): string
    {
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? '';

        return Str::lower($name);
    }

    private function normalizeAssetUrl(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://', 'data:'])) {
            return $value;
        }

        $path = Str::startsWith($value, '/') ? $value : ('/' . ltrim($value, '/'));

        return url($path);
    }

    private function settingString($settings, string $key): ?string
    {
        if (!isset($settings[$key])) {
            return null;
        }

        $value = $settings[$key]->value;
        if (is_array($value)) {
            foreach (['url', 'path', 'value', 'text', 'logo'] as $variant) {
                $variantValue = isset($value[$variant]) ? trim((string) $value[$variant]) : '';
                if ($variantValue !== '') {
                    return $variantValue;
                }
            }

            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (strlen($trimmed) >= 2 && $trimmed[0] === '"' && substr($trimmed, -1) === '"') {
                $decoded = json_decode($trimmed, true);
                if (is_string($decoded)) {
                    $decoded = trim($decoded);

                    return $decoded === '' ? null : $decoded;
                }
            }

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_scalar($value)) {
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    private function settingArray($settings, string $key): ?array
    {
        if (!isset($settings[$key])) {
            return null;
        }

        $value = $settings[$key]->value;
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && Str::startsWith(trim($value), ['{', '['])) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
