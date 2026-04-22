<?php

namespace App\Services;

use App\Models\Pc;
use App\Models\PcCell;
use App\Models\Zone;

class PcZoneResolver
{
    public function resolve(Pc $pc): ?Zone
    {
        $pc->loadMissing('zoneRel');
        $zone = $pc->zoneRel;

        if (!$zone && !empty($pc->zone_id)) {
            $zone = Zone::query()
                ->where('tenant_id', $pc->tenant_id)
                ->where('id', (int) $pc->zone_id)
                ->first();
        }

        if (!$zone && !empty($pc->zone)) {
            $zone = Zone::query()
                ->where('tenant_id', $pc->tenant_id)
                ->whereRaw('lower(name) = lower(?)', [$pc->zone])
                ->first();
        }

        if (!$zone) {
            $cellZoneId = PcCell::query()
                ->where('tenant_id', $pc->tenant_id)
                ->where('pc_id', $pc->id)
                ->whereNotNull('zone_id')
                ->orderByDesc('id')
                ->value('zone_id');

            if ($cellZoneId) {
                $zone = Zone::query()
                    ->where('tenant_id', $pc->tenant_id)
                    ->where('id', (int) $cellZoneId)
                    ->first();
            }
        }

        return $zone;
    }

    public function resolveNameAndRate(Pc $pc): array
    {
        $zone = $this->resolve($pc);

        return [
            'zone_name' => $zone?->name ?? ($pc->zone ?: null),
            'rate_per_hour' => $zone ? (int) $zone->price_per_hour : 0,
        ];
    }
}
