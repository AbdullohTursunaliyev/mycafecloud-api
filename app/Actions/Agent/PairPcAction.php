<?php

namespace App\Actions\Agent;

use App\Enums\PcStatus;
use App\Models\Pc;
use App\Models\PcPairCode;
use App\Models\Zone;
use App\Services\PcDeviceTokenService;
use App\ValueObjects\Agent\AgentPairResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PairPcAction
{
    public function __construct(
        private readonly PcDeviceTokenService $deviceTokens,
    ) {
    }

    public function execute(PcPairCode $pair, array $attributes): AgentPairResult
    {
        return DB::transaction(function () use ($pair, $attributes) {
            $lockedPair = PcPairCode::query()
                ->lockForUpdate()
                ->findOrFail($pair->id);

            if ($lockedPair->used_at) {
                throw ValidationException::withMessages(['pair_code' => 'Код уже использован']);
            }

            if ($lockedPair->expires_at?->lte(Carbon::now())) {
                throw ValidationException::withMessages(['pair_code' => 'Код истёк']);
            }

            $isRepair = $lockedPair->pc_id !== null;
            $zoneId = null;
            if ($lockedPair->zone) {
                $zoneId = Zone::query()
                    ->where('tenant_id', $lockedPair->tenant_id)
                    ->where('name', $lockedPair->zone)
                    ->value('id');
            }

            $pc = $isRepair
                ? Pc::query()
                    ->where('tenant_id', $lockedPair->tenant_id)
                    ->lockForUpdate()
                    ->findOrFail($lockedPair->pc_id)
                : Pc::query()->create([
                    'tenant_id' => $lockedPair->tenant_id,
                    'code' => $this->uniquePcCode((int) $lockedPair->tenant_id, (string) ($attributes['pc_name'] ?? '')),
                    'status' => PcStatus::Online->value,
                    'ip_address' => $attributes['ip'] ?? null,
                    'last_seen_at' => now(),
                    'zone_id' => $zoneId,
                    'zone' => $lockedPair->zone,
                ]);

            if ($isRepair) {
                $updates = [
                    'status' => PcStatus::Online->value,
                    'ip_address' => $attributes['ip'] ?? $pc->ip_address,
                    'last_seen_at' => now(),
                ];

                if ($lockedPair->zone !== null) {
                    $updates['zone_id'] = $zoneId;
                    $updates['zone'] = $lockedPair->zone;
                }

                $pc->update($updates);
            }

            $this->deviceTokens->revokeActiveForPc((int) $lockedPair->tenant_id, (int) $pc->id, 're_paired');
            $issued = $this->deviceTokens->issue((int) $lockedPair->tenant_id, (int) $pc->id);

            $lockedPair->update([
                'used_at' => now(),
                'pc_id' => $pc->id,
            ]);

            return new AgentPairResult(
                pcId: (int) $pc->id,
                pcCode: (string) $pc->code,
                zone: $pc->zone ? (string) $pc->zone : null,
                deviceToken: (string) $issued['plain'],
                deviceTokenExpiresAt: $issued['token']->expires_at,
                pollIntervalSec: (int) config('domain.agent.poll_interval_seconds', 3),
                repairMode: $isRepair,
            );
        });
    }

    private function uniquePcCode(int $tenantId, string $requestedCode): string
    {
        $prefix = (string) config('domain.pc.default_code_prefix', 'PC-');
        $pcCode = trim($requestedCode) !== ''
            ? trim($requestedCode)
            : ($prefix . Str::upper(Str::random(4)));

        $base = $pcCode;
        $i = 1;

        while (Pc::query()->where('tenant_id', $tenantId)->where('code', $pcCode)->exists()) {
            $pcCode = $base . '-' . $i++;
        }

        return $pcCode;
    }
}
