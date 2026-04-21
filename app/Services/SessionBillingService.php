<?php

namespace App\Services;

use App\Models\ClientPackage;
use App\Models\PcCommand;
use App\Models\PcCell;
use App\Models\Session;
use App\Models\SessionBillingLog;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SessionBillingService
{
    public function billSingleSession(Session $s, ?Carbon $now = null): array
    {
        $now = ($now ?: now())->copy()->second(0);

        $from = ($s->last_billed_at ?: $s->started_at ?: $now)->copy()->second(0);
        if ($from->gte($now)) {
            return ['billed' => false, 'stopped' => false, 'skipped' => true];
        }

        $minutes = (int) floor($from->diffInSeconds($now) / 60);
        if ($minutes <= 0) {
            return ['billed' => false, 'stopped' => false, 'skipped' => true];
        }

        // Server uzoq o‘chib qolsa ham bir tickda haddan oshirmaymiz
        $minutes = min($minutes, 60);

        return $this->billOneSession($s, $minutes, $now);
    }

    public function billForLogout(Session $s, ?Carbon $now = null): array
    {
        $now = ($now ?: now())->copy()->second(0);

        $from = ($s->last_billed_at ?: $s->started_at ?: $now)->copy()->second(0);
        if ($from->gte($now)) {
            return ['billed' => false, 'stopped' => false, 'skipped' => true];
        }

        $minutes = (int) ceil($from->diffInSeconds($now) / 60);
        if ($minutes <= 0) {
            return ['billed' => false, 'stopped' => false, 'skipped' => true];
        }

        return $this->billOneSession($s, $minutes, $now);
    }

    public function tick(?Carbon $now = null, int $batchSize = 200): array
    {
        $now = ($now ?: now())->copy()->second(0);

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

                    $from = ($s->last_billed_at ?: $s->started_at ?: $now)->copy()->second(0);
                    if ($from->gte($now)) { $stats['skipped']++; continue; }

                    $minutes = (int) floor($from->diffInSeconds($now) / 60);
                    if ($minutes <= 0) { $stats['skipped']++; continue; }

                    // Server uzoq o‘chib qolsa ham bir tickda haddan oshirmaymiz
                    $minutes = min($minutes, 60);

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

            // ✅ PACKAGE billing
            if ((bool) $s->is_package && $s->client_package_id) {
                $cp = ClientPackage::query()->whereKey($s->client_package_id)->lockForUpdate()->first();
                if (!$cp || $cp->status !== 'active') {
                    $this->stopSession($s, $now, 'package_missing');
                    return ['billed' => false, 'stopped' => true];
                }

                $use = min($minutes, max(0, (int) $cp->remaining_min));
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

                $s->last_billed_at = ($s->last_billed_at ?: $s->started_at ?: $now)
                    ->copy()->second(0)->addMinutes($use);
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

                if ($cp->remaining_min <= 0) {
                    $this->stopSession($s, $now, 'package_finished');
                    return ['billed' => true, 'stopped' => true];
                }

                return ['billed' => true, 'stopped' => false];
            }

            // ✅ MONEY billing
            $pricePerHour = $this->resolvePricePerHour($s);
            if ($pricePerHour <= 0) {
                // Tarif aniqlanmasa sessiyani majburan yopirmaymiz.
                // Aks holda client asossiz "otvor" bo'lib qoladi.
                $s->last_billed_at = $now;
                $s->save();
                Log::warning('billing_no_price_skip', [
                    'session_id' => $s->id,
                    'tenant_id' => $s->tenant_id,
                    'pc_id' => $s->pc_id,
                ]);
                return ['billed' => false, 'stopped' => false];
            }

            $pricePerMin = (int) ceil($pricePerHour / 60);
            $walletTotal = $this->walletTotal($client);
            $canMinutes = $pricePerMin > 0 ? (int) floor($walletTotal / $pricePerMin) : 0;
            $use = min($minutes, max(0, $canMinutes));

            if ($use <= 0) {
                $this->stopSession($s, $now, 'balance_empty');
                return ['billed' => false, 'stopped' => true];
            }

            $sum = $pricePerMin * $use;
            $chargeInfo = $this->chargeWallet($client, $sum);
            $charged = $chargeInfo['charged'];
            if ($charged <= 0) {
                $this->stopSession($s, $now, 'balance_empty');
                return ['billed' => false, 'stopped' => true];
            }

            $s->price_total = (int) $s->price_total + $charged;
            $s->last_billed_at = ($s->last_billed_at ?: $s->started_at ?: $now)
                ->copy()->second(0)->addMinutes($use);
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
                'reason' => $use < $minutes ? 'balance_finished' : 'billing_tick',
            ]);

            if ($use < $minutes) {
                $this->stopSession($s, $now, 'balance_finished');
                return ['billed' => true, 'stopped' => true];
            }

            return ['billed' => true, 'stopped' => false];
        });
    }

    private function resolvePricePerHour(Session $s): int
    {
        // 1) tariff bo'lsa
        if ($s->tariff_id && $s->relationLoaded('tariff') && $s->tariff) {
            return (int) $s->tariff->price_per_hour;
        }
        if ($s->tariff_id) {
            $pph = (int) optional($s->tariff()->first())->price_per_hour;
            if ($pph > 0) return $pph;
        }

        // 2) zoneRel (pcs.zone_id)
        if ($s->relationLoaded('pc') && $s->pc) {
            if ($s->pc->zoneRel) return (int) $s->pc->zoneRel->price_per_hour;

            // 2.1) fallback: pcs.zone_id mavjud bo'lsa, zones.id orqali topamiz
            if (!empty($s->pc->zone_id)) {
                $zById = Zone::query()
                    ->where('tenant_id', $s->tenant_id)
                    ->where('id', (int) $s->pc->zone_id)
                    ->first();
                if ($zById) return (int) $zById->price_per_hour;
            }

            // 3) fallback: pcs.zone string
            if (!empty($s->pc->zone)) {
                $z = Zone::query()
                    ->where('tenant_id', $s->tenant_id)
                    ->whereRaw('lower(name) = lower(?)', [$s->pc->zone])
                    ->first();
                if ($z) return (int) $z->price_per_hour;
            }

            // 4) fallback: layout cell zone (pc_cells.zone_id)
            $cellZoneId = PcCell::query()
                ->where('tenant_id', $s->tenant_id)
                ->where('pc_id', $s->pc->id)
                ->whereNotNull('zone_id')
                ->orderByDesc('id')
                ->value('zone_id');

            if ($cellZoneId) {
                $zCell = Zone::query()
                    ->where('tenant_id', $s->tenant_id)
                    ->where('id', (int) $cellZoneId)
                    ->first();
                if ($zCell) return (int) $zCell->price_per_hour;
            }
        }

        return 0;
    }

    private function stopSession(Session $s, Carbon $now, string $reason): void
    {
        $s->ended_at = $now;
        $s->status = 'finished';
        $s->save();

        $pc = $s->pc()->first();
        if ($pc) {
            $pc->status = 'locked';
            $pc->save();

            PcCommand::create([
                'tenant_id' => $s->tenant_id,
                'pc_id' => $pc->id,
                'type' => 'LOCK',
                'payload' => ['reason' => $reason],
                'status' => 'pending',
            ]);
        }
    }

    private function walletTotal($client): int
    {
        $balance = max(0, (int) ($client->balance ?? 0));
        $bonus = max(0, (int) ($client->bonus ?? 0));
        return $balance + $bonus;
    }

    private function chargeWallet($client, int $amount): array
    {
        $need = max(0, (int) $amount);
        if ($need <= 0) {
            return [
                'charged' => 0,
                'balance_before' => (int) ($client->balance ?? 0),
                'bonus_before' => (int) ($client->bonus ?? 0),
                'balance_after' => (int) ($client->balance ?? 0),
                'bonus_after' => (int) ($client->bonus ?? 0),
            ];
        }

        $balance = max(0, (int) ($client->balance ?? 0));
        $bonus = max(0, (int) ($client->bonus ?? 0));
        $available = $balance + $bonus;
        if ($available <= 0) {
            return [
                'charged' => 0,
                'balance_before' => $balance,
                'bonus_before' => $bonus,
                'balance_after' => $balance,
                'bonus_after' => $bonus,
            ];
        }

        $charge = min($need, $available);

        $fromBalance = min($balance, $charge);
        $left = $charge - $fromBalance;

        $fromBonus = min($bonus, $left);

        $client->balance = $balance - $fromBalance;
        $client->bonus = $bonus - $fromBonus;
        $client->save();

        return [
            'charged' => $charge,
            'balance_before' => $balance,
            'bonus_before' => $bonus,
            'balance_after' => (int) $client->balance,
            'bonus_after' => (int) $client->bonus,
        ];
    }
}
