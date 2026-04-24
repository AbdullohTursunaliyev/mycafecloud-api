<?php

namespace App\Services;

use App\Models\ClientPackage;
use App\Models\Session;
use App\Models\SessionBillingLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SessionBillingService
{
    public function __construct(
        private readonly ClientWalletService $wallets,
        private readonly SessionMeteringService $metering,
        private readonly PricingRuleResolver $pricingRules,
        private readonly SessionChargeEventService $chargeEvents,
    ) {
    }

    public function billSingleSession(Session $s, ?Carbon $now = null): array
    {
        $now = ($now ?: now())->copy();
        $minutes = $this->metering->completedMinutes($s, $now, 60);
        if ($minutes <= 0) {
            return ['billed' => false, 'stopped' => false, 'skipped' => true];
        }

        return $this->billOneSession($s, $minutes, $now);
    }

    public function billForLogout(Session $s, ?Carbon $now = null): array
    {
        $now = ($now ?: now())->copy();
        $minutes = $this->metering->completedMinutes($s, $now);
        if ($minutes <= 0) {
            return ['billed' => false, 'stopped' => false, 'skipped' => true];
        }

        return $this->billOneSession($s, $minutes, $now);
    }

    public function tick(?Carbon $now = null, int $batchSize = 200): array
    {
        $now = ($now ?: now())->copy();

        $stats = [
            'processed' => 0,
            'billed_sessions' => 0,
            'stopped_sessions' => 0,
            'skipped' => 0,
        ];

        Session::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->with(['pc','pc.zoneRel','tariff','client','clientPackage'])
            ->chunkById($batchSize, function ($sessions) use ($now, &$stats) {
                foreach ($sessions as $s) {
                    $stats['processed']++;

                    if ($s->ended_at) { $stats['skipped']++; continue; }

                    $minutes = $this->metering->completedMinutes($s, $now, 60);
                    if ($minutes <= 0) { $stats['skipped']++; continue; }

                    try {
                        $result = $this->billOneSession($s->fresh(), $minutes, $now);
                        $stats['billed_sessions'] += $result['billed'] ? 1 : 0;
                        $stats['stopped_sessions'] += $result['stopped'] ? 1 : 0;
                    } catch (\Throwable $e) {
                        Log::error('billing_tick_error', [
                            'session_id' => $s->id,
                            'tenant_id' => $s->tenant_id,
                            'err' => $e->getMessage(),
                        ]);
                        $stats['skipped']++;
                    }
                }
            });

        return $stats;
    }

    private function billOneSession(Session $s, int $minutes, Carbon $now): array
    {
        return DB::transaction(function () use ($s, $minutes, $now) {
            $s = Session::query()->whereKey($s->id)->lockForUpdate()->firstOrFail();
            $s->loadMissing(['pc', 'pc.zoneRel', 'tariff', 'client', 'clientPackage']);
            if ($s->status !== 'active' || $s->ended_at) {
                return ['billed' => false, 'stopped' => false];
            }

            $client = $s->client()->lockForUpdate()->first();
            if (!$client) {
                $this->stopSession($s, $now, 'client_missing');
                return ['billed' => false, 'stopped' => true];
            }

            $segments = $this->pricingRules->resolveSegments($s, $minutes, $now);
            if ($segments === []) {
                return ['billed' => false, 'stopped' => false];
            }

            // ✅ PACKAGE billing
            if ((bool) $s->is_package && $s->client_package_id) {
                $cp = ClientPackage::query()->whereKey($s->client_package_id)->lockForUpdate()->first();
                if (!$cp || $cp->status !== 'active') {
                    $this->stopSession($s, $now, 'package_missing');
                    return ['billed' => false, 'stopped' => true];
                }

                $segment = $segments[0];
                $use = min((int) $segment['billable_units'], max(0, (int) $cp->remaining_min));
                if ($use <= 0) {
                    $this->stopSession($s, $now, 'package_empty');
                    return ['billed' => false, 'stopped' => true];
                }

                $beforeRemaining = (int) $cp->remaining_min;
                $cp->remaining_min = (int) $cp->remaining_min - $use;
                if ($cp->remaining_min <= 0) {
                    $cp->remaining_min = 0;
                    $cp->status = 'used';
                }
                $cp->save();

                $periodEndedAt = $segment['period_started_at']->copy()->addMinutes($use);
                $s->last_billed_at = $periodEndedAt;
                $s->save();

                SessionBillingLog::create([
                    'tenant_id' => $s->tenant_id,
                    'session_id' => $s->id,
                    'client_id' => $s->client_id,
                    'pc_id' => $s->pc_id,
                    'mode' => 'package',
                    'minutes' => $use,
                    'amount' => 0,
                    'remaining_min_before' => $beforeRemaining,
                    'remaining_min_after' => (int) $cp->remaining_min,
                    'reason' => $cp->remaining_min <= 0 ? 'package_finished' : 'package_tick',
                ]);

                $this->chargeEvents->record($s, [
                    'zone_id' => $segment['zone_id'],
                    'source_type' => 'package',
                    'rule_type' => $segment['rule_type'],
                    'rule_id' => $segment['rule_id'],
                    'period_started_at' => $segment['period_started_at'],
                    'period_ended_at' => $periodEndedAt,
                    'billable_units' => $use,
                    'unit_kind' => 'minute',
                    'unit_price' => 0,
                    'amount' => 0,
                    'package_before_min' => $beforeRemaining,
                    'package_after_min' => (int) $cp->remaining_min,
                    'meta' => [
                        'window_id' => $segment['window_id'],
                        'window_name' => $segment['window_name'],
                        'reason' => $cp->remaining_min <= 0 ? 'package_finished' : 'package_tick',
                    ],
                ]);

                if ($cp->remaining_min <= 0 || $use < $minutes) {
                    $this->stopSession($s, $now, 'package_finished');
                    return ['billed' => true, 'stopped' => true];
                }

                return ['billed' => true, 'stopped' => false];
            }

            // ✅ MONEY billing with rule segmentation
            foreach ($segments as $segment) {
                $pricePerHour = max(0, (int) ($segment['rate_per_hour'] ?? 0));
                $pricePerMin = max(0, (int) ($segment['unit_price'] ?? 0));
                if ($pricePerHour <= 0 || $pricePerMin <= 0) {
                    $s->last_billed_at = $segment['period_ended_at'];
                    $s->save();
                    Log::warning('billing_no_price_skip', [
                        'session_id' => $s->id,
                        'tenant_id' => $s->tenant_id,
                        'pc_id' => $s->pc_id,
                        'rule_type' => $segment['rule_type'] ?? null,
                        'rule_id' => $segment['rule_id'] ?? null,
                    ]);
                    continue;
                }

                $walletTotal = $this->wallets->total($client);
                $canMinutes = $this->metering->affordableMinutes($walletTotal, $pricePerHour);
                $use = min((int) $segment['billable_units'], max(0, $canMinutes));

                if ($use <= 0) {
                    $this->stopSession($s, $now, 'balance_empty');
                    return ['billed' => false, 'stopped' => true];
                }

                $sum = $pricePerMin * $use;
                $chargeInfo = $this->wallets->charge($client, $sum);
                $charged = $chargeInfo['charged'];
                if ($charged <= 0) {
                    $this->stopSession($s, $now, 'balance_empty');
                    return ['billed' => false, 'stopped' => true];
                }

                $periodEndedAt = $segment['period_started_at']->copy()->addMinutes($use);
                $s->price_total = (int) $s->price_total + $charged;
                $s->last_billed_at = $periodEndedAt;
                $s->save();

                SessionBillingLog::create([
                    'tenant_id' => $s->tenant_id,
                    'session_id' => $s->id,
                    'client_id' => $s->client_id,
                    'pc_id' => $s->pc_id,
                    'mode' => 'wallet',
                    'minutes' => $use,
                    'amount' => $charged,
                    'price_per_hour' => $pricePerHour,
                    'price_per_min' => $pricePerMin,
                    'balance_before' => $chargeInfo['balance_before'],
                    'bonus_before' => $chargeInfo['bonus_before'],
                    'balance_after' => $chargeInfo['balance_after'],
                    'bonus_after' => $chargeInfo['bonus_after'],
                    'reason' => $use < (int) $segment['billable_units'] ? 'balance_finished' : 'billing_tick',
                ]);

                $this->chargeEvents->record($s, [
                    'zone_id' => $segment['zone_id'],
                    'source_type' => 'wallet',
                    'rule_type' => $segment['rule_type'],
                    'rule_id' => $segment['rule_id'],
                    'period_started_at' => $segment['period_started_at'],
                    'period_ended_at' => $periodEndedAt,
                    'billable_units' => $use,
                    'unit_kind' => 'minute',
                    'unit_price' => $pricePerMin,
                    'amount' => $charged,
                    'wallet_before' => ($chargeInfo['balance_before'] ?? 0) + ($chargeInfo['bonus_before'] ?? 0),
                    'wallet_after' => ($chargeInfo['balance_after'] ?? 0) + ($chargeInfo['bonus_after'] ?? 0),
                    'meta' => [
                        'window_id' => $segment['window_id'],
                        'window_name' => $segment['window_name'],
                        'price_per_hour' => $pricePerHour,
                        'reason' => $use < (int) $segment['billable_units'] ? 'balance_finished' : 'billing_tick',
                    ],
                ]);

                if ($use < (int) $segment['billable_units']) {
                    $this->stopSession($s, $now, 'balance_finished');
                    return ['billed' => true, 'stopped' => true];
                }
            }

            return ['billed' => true, 'stopped' => false];
        });
    }

    private function stopSession(Session $s, Carbon $now, string $reason): void
    {
        $s->ended_at = $now;
        $s->status = 'finished';
        $s->paused_at = null;
        $s->save();

            $pc = $s->pc()->first();
        if ($pc) {
            $pc->status = 'locked';
            $pc->save();

            \App\Models\PcCommand::create([
                'tenant_id' => $s->tenant_id,
                'pc_id' => $pc->id,
                'type' => 'LOCK',
                'payload' => ['reason' => $reason],
                'status' => 'pending',
            ]);
        }
    }

}
