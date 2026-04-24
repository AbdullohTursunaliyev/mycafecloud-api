<?php

namespace App\Services;

use App\Models\Pc;
use App\Models\Session;
use App\Models\Zone;
use App\Models\ZonePricingWindow;
use Carbon\Carbon;

class PricingRuleResolver
{
    private array $windowCache = [];

    public function __construct(
        private readonly PcZoneResolver $pcZoneResolver,
        private readonly SessionMeteringService $metering,
    ) {
    }

    public function resolveCurrent(Session $session, ?Carbon $at = null): array
    {
        $at = $this->metering->effectiveNow($session, $at);
        $session->loadMissing(['pc.zoneRel', 'tariff', 'clientPackage.package']);

        $pc = $session->pc;
        $zone = $pc ? $this->pcZoneResolver->resolve($pc) : null;
        $zoneName = $zone?->name ?? ($pc?->zone ?: null);

        if ((bool) $session->is_package && $session->client_package_id) {
            return [
                'source_type' => 'package',
                'source_id' => (int) $session->client_package_id,
                'rule_type' => 'package',
                'rule_id' => (int) $session->client_package_id,
                'rate_per_hour' => $zone ? (int) $zone->price_per_hour : 0,
                'unit_price' => 0,
                'zone_id' => $zone?->id,
                'zone_name' => $zoneName,
                'window_id' => null,
                'window_name' => null,
            ];
        }

        if ($session->tariff_id) {
            $tariff = $session->relationLoaded('tariff') ? $session->tariff : $session->tariff()->first();
            $ratePerHour = max(0, (int) ($tariff?->price_per_hour ?? 0));

            return [
                'source_type' => 'wallet',
                'source_id' => null,
                'rule_type' => 'tariff',
                'rule_id' => $tariff?->id ? (int) $tariff->id : null,
                'rate_per_hour' => $ratePerHour,
                'unit_price' => $this->metering->pricePerMinute($ratePerHour),
                'zone_id' => $zone?->id,
                'zone_name' => $zoneName ?: ($tariff?->zone ?: null),
                'window_id' => null,
                'window_name' => null,
            ];
        }

        return $this->resolvePcRule($pc, $zone, $at);
    }

    public function resolveSegments(Session $session, int $minutes, ?Carbon $now = null): array
    {
        $minutes = max(0, $minutes);
        if ($minutes === 0) {
            return [];
        }

        $now = $this->metering->effectiveNow($session, $now);
        $anchor = $this->metering->billingAnchor($session, $now) ?: $now->copy();

        $segments = [];
        for ($offset = 0; $offset < $minutes; $offset++) {
            $minuteStart = $anchor->copy()->addMinutes($offset);
            $rule = $this->resolveCurrent($session, $minuteStart);
            $segmentKey = implode(':', [
                $rule['source_type'] ?? '',
                $rule['rule_type'] ?? '',
                $rule['rule_id'] ?? '',
                $rule['rate_per_hour'] ?? 0,
                $rule['window_id'] ?? '',
            ]);

            if (!empty($segments) && $segments[array_key_last($segments)]['segment_key'] === $segmentKey) {
                $lastKey = array_key_last($segments);
                $segments[$lastKey]['billable_units']++;
                $segments[$lastKey]['period_ended_at'] = $minuteStart->copy()->addMinute();
                continue;
            }

            $segments[] = [
                'segment_key' => $segmentKey,
                'source_type' => $rule['source_type'],
                'rule_type' => $rule['rule_type'],
                'rule_id' => $rule['rule_id'],
                'rate_per_hour' => $rule['rate_per_hour'],
                'unit_price' => $rule['unit_price'],
                'zone_id' => $rule['zone_id'],
                'zone_name' => $rule['zone_name'],
                'window_id' => $rule['window_id'],
                'window_name' => $rule['window_name'],
                'period_started_at' => $minuteStart->copy(),
                'period_ended_at' => $minuteStart->copy()->addMinute(),
                'billable_units' => 1,
                'unit_kind' => 'minute',
            ];
        }

        return array_map(function (array $segment) {
            unset($segment['segment_key']);

            return $segment;
        }, $segments);
    }

    public function projectWalletBillableMinutes(Session $session, int $walletTotal, ?Carbon $now = null, int $maxMinutes = 10080): int
    {
        $remaining = max(0, $walletTotal);
        if ($remaining <= 0) {
            return 0;
        }

        $minutes = 0;
        $anchor = $this->metering->billingAnchor($session, $now) ?: ($now ?: now())->copy();
        for ($offset = 0; $offset < $maxMinutes; $offset++) {
            $rule = $this->resolveCurrent($session, $anchor->copy()->addMinutes($offset));
            $unitPrice = max(0, (int) ($rule['unit_price'] ?? 0));
            if ($unitPrice <= 0 || $remaining < $unitPrice) {
                break;
            }

            $remaining -= $unitPrice;
            $minutes++;
        }

        return $minutes;
    }

    private function resolvePcRule(?Pc $pc, ?Zone $zone, Carbon $at): array
    {
        $zoneName = $zone?->name ?? ($pc?->zone ?: null);
        $baseRate = $zone ? (int) $zone->price_per_hour : 0;

        $window = $zone ? $this->findActiveWindow($zone, $at) : null;
        $ratePerHour = $window ? (int) $window->price_per_hour : $baseRate;

        return [
            'source_type' => 'wallet',
            'source_id' => null,
            'rule_type' => $window ? 'zone_pricing_window' : 'zone',
            'rule_id' => $window ? (int) $window->id : ($zone?->id ? (int) $zone->id : null),
            'rate_per_hour' => $ratePerHour,
            'unit_price' => $this->metering->pricePerMinute($ratePerHour),
            'zone_id' => $zone?->id,
            'zone_name' => $zoneName,
            'window_id' => $window?->id ? (int) $window->id : null,
            'window_name' => $window?->name,
        ];
    }

    private function findActiveWindow(Zone $zone, Carbon $at): ?ZonePricingWindow
    {
        $windows = $this->windowsForZone((int) $zone->tenant_id, (int) $zone->id);
        $match = null;

        foreach ($windows as $window) {
            if (!$this->matchesWindow($window, $at)) {
                continue;
            }

            if (!$match || $window->id > $match->id) {
                $match = $window;
            }
        }

        return $match;
    }

    private function windowsForZone(int $tenantId, int $zoneId): array
    {
        $cacheKey = $tenantId . ':' . $zoneId;

        if (!array_key_exists($cacheKey, $this->windowCache)) {
            $this->windowCache[$cacheKey] = ZonePricingWindow::query()
                ->where('tenant_id', $tenantId)
                ->where('zone_id', $zoneId)
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
                ->all();
        }

        return $this->windowCache[$cacheKey];
    }

    private function matchesWindow(ZonePricingWindow $window, Carbon $at): bool
    {
        $start = $this->minutesFromTime((string) $window->starts_at);
        $end = $this->minutesFromTime((string) $window->ends_at);
        $minutesNow = ((int) $at->format('H')) * 60 + (int) $at->format('i');
        $weekdays = array_values(array_map('intval', (array) ($window->weekdays ?? [])));
        $currentDay = (int) $at->isoWeekday();
        $previousDay = (int) $at->copy()->subDay()->isoWeekday();
        $scheduleDate = $at->copy();
        $scheduleDay = $currentDay;

        if ($start === $end) {
            return $this->matchesDateRange($window, $scheduleDate)
                && $this->matchesWeekday($weekdays, $scheduleDay);
        }

        if ($start < $end) {
            return $this->matchesDateRange($window, $scheduleDate)
                && $this->matchesWeekday($weekdays, $scheduleDay)
                && $minutesNow >= $start
                && $minutesNow < $end;
        }

        if ($minutesNow >= $start) {
            return $this->matchesDateRange($window, $scheduleDate)
                && $this->matchesWeekday($weekdays, $scheduleDay);
        }

        $scheduleDate = $at->copy()->subDay();
        $scheduleDay = $previousDay;

        return $minutesNow < $end
            && $this->matchesDateRange($window, $scheduleDate)
            && $this->matchesWeekday($weekdays, $scheduleDay);
    }

    private function matchesWeekday(array $weekdays, int $day): bool
    {
        if ($weekdays === []) {
            return true;
        }

        return in_array($day, $weekdays, true);
    }

    private function matchesDateRange(ZonePricingWindow $window, Carbon $scheduleDate): bool
    {
        $date = $scheduleDate->toDateString();
        $startsOn = $window->starts_on?->toDateString();
        $endsOn = $window->ends_on?->toDateString();

        if ($startsOn && $date < $startsOn) {
            return false;
        }

        if ($endsOn && $date > $endsOn) {
            return false;
        }

        return true;
    }

    private function minutesFromTime(string $value): int
    {
        [$hour, $minute] = array_pad(explode(':', $value), 2, '0');

        return ((int) $hour) * 60 + (int) $minute;
    }
}
