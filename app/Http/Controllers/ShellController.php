<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\LicenseKey;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\PcCell;
use App\Models\Session;
use App\Models\Zone;
use App\Services\SessionBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShellController extends Controller
{
    private function resolveTenant(string $licenseKey)
    {
        $license = LicenseKey::with('tenant')
            ->where('key', $licenseKey)
            ->first();

        if (!$license || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast())) {
            throw ValidationException::withMessages(['license_key' => 'Лицензия недействительна']);
        }
        if ($license->tenant?->status !== 'active') {
            throw ValidationException::withMessages(['license_key' => 'Клуб заблокирован']);
        }

        return $license->tenant_id;
    }

    private function resolveZone(Pc $pc): ?Zone
    {
        $pc->loadMissing('zoneRel');
        $zone = $pc->zoneRel;

        if (!$zone && !empty($pc->zone_id)) {
            $zone = Zone::where('tenant_id', $pc->tenant_id)
                ->where('id', (int)$pc->zone_id)
                ->first();
        }

        if (!$zone && !empty($pc->zone)) {
            $zone = Zone::where('tenant_id', $pc->tenant_id)
                ->whereRaw('lower(name) = lower(?)', [$pc->zone])
                ->first();
        }

        if (!$zone) {
            $cellZoneId = PcCell::where('tenant_id', $pc->tenant_id)
                ->where('pc_id', $pc->id)
                ->whereNotNull('zone_id')
                ->orderByDesc('id')
                ->value('zone_id');

            if ($cellZoneId) {
                $zone = Zone::where('tenant_id', $pc->tenant_id)
                    ->where('id', (int)$cellZoneId)
                    ->first();
            }
        }

        return $zone;
    }

    public function sessionState(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required','string'],
            'pc_code'        => ['required','string'], // pcs.code (VIP01)
        ]);

        $tenantId = $this->resolveTenant($data['license_key']);

        $pc = Pc::with('zoneRel')
            ->where('tenant_id', $tenantId)
            ->where('code', $data['pc_code'])
            ->first();

        if (!$pc) {
            throw ValidationException::withMessages(['code' => 'ПК не найден']);
        }

        $zone = $this->resolveZone($pc);
        $zoneName = $zone?->name ?? ($pc->zone ?: null);
        $pricePerHour = (int)($zone?->price_per_hour ?? 0);

        $session = Session::where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('status', 'active')
            ->first();

        if (!$session) {
            return response()->json([
                'has_session' => false,
                'pc' => [
                    'id' => $pc->id,
                    'code' => $pc->code,
                    'zone' => $zoneName,
                    'status' => $pc->status,
                ],
                'zone' => [
                    'name' => $zoneName,
                    'price_per_hour' => $pricePerHour,
                ],
            ]);
        }

        $client = Client::where('tenant_id', $tenantId)
            ->where('id', $session->client_id)
            ->first();

        // ==== LEFT SECONDS CALC ====
        $leftSeconds = null;
        $anchor = $session->last_billed_at ?? $session->started_at;
        $elapsedSec = $anchor ? max(0, (int)$anchor->diffInSeconds(now())) : 0;

        // 1) package bo‘lsa
        if ($session->is_package && $session->client_package_id) {
            $cp = ClientPackage::where('tenant_id', $tenantId)
                ->where('id', $session->client_package_id)
                ->where('client_id', $session->client_id)
                ->first();

            if ($cp && $cp->status === 'active') {
                // remaining_min -> seconds
                $secByRemaining = max(0, (int)$cp->remaining_min * 60);

                // expires_at bo‘lsa — undan ham aniqroq
                $secByExpires = null;
                if ($cp->expires_at) {
                    $secByExpires = max(0, now()->diffInSeconds($cp->expires_at, false));
                }

                if ($secByExpires !== null) {
                    $leftSeconds = min($secByRemaining, $secByExpires);
                } else {
                    $leftSeconds = $secByRemaining;
                }
                $leftSeconds = max(0, (int)$leftSeconds - $elapsedSec);

                // package tugasa — sessiyani balans rejimiga o‘tkazamiz
                if ($leftSeconds <= 0) {
                    DB::transaction(function() use ($session, $cp) {
                        $session->is_package = false;
                        $session->client_package_id = null;
                        $session->save();

                        $cp->status = 'finished';
                        $cp->save();
                    });

                    $leftSeconds = null; // endi balansdan hisoblaymiz pastda
                }
            } else {
                // package topilmadi yoki active emas — balansga o‘tamiz
                DB::transaction(function() use ($session) {
                    $session->is_package = false;
                    $session->client_package_id = null;
                    $session->save();
                });
            }
        }

        // 2) balans bo‘yicha (agar package ishlamasa yoki tugagan bo‘lsa)
        if ($leftSeconds === null) {
            if ($client && $pricePerHour > 0) {
                $wallet = (int)($client->balance ?? 0) + (int)($client->bonus ?? 0);
                $rawSeconds = (int) floor(($wallet / $pricePerHour) * 3600);
                $leftSeconds = max(0, $rawSeconds - $elapsedSec);
            } else {
                $leftSeconds = null;
            }
        }

        return response()->json([
            'has_session' => true,
            'pc' => [
                'id' => $pc->id,
                'code' => $pc->code,
                'zone' => $zoneName,
                'status' => $pc->status,
            ],
            'zone' => [
                'name' => $zoneName,
                'price_per_hour' => $pricePerHour,
            ],
            'client' => $client ? [
                'id' => $client->id,
                'login' => $client->login,
                'balance' => $client->balance,
                'bonus' => $client->bonus,
            ] : null,
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'started_at' => (string) $session->started_at,
                'is_package' => (bool) $session->is_package,
            ],
            'left_seconds' => $leftSeconds,
        ]);
    }

    public function logout(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required','string'],
            'pc_code'        => ['required','string'],
        ]);

        $tenantId = $this->resolveTenant($data['license_key']);

        $pc = Pc::where('tenant_id', $tenantId)
            ->where('code', $data['pc_code'])
            ->first();

        if (!$pc) {
            throw ValidationException::withMessages(['code' => 'ПК не найден']);
        }

        $session = Session::where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        if ($session) {
            $billing = app(SessionBillingService::class);
            try {
                $billing->billForLogout($session);
            } catch (\Throwable $e) {
                // billing error should not block logout
            }

            $session->refresh();
            if (!$session->ended_at) {
                DB::transaction(function () use ($session, $pc) {
                    $session->refresh();
                    if ($session->ended_at) return;

                    $now = now();
                    $session->last_billed_at = $session->last_billed_at ?: $now;
                    $session->ended_at = $now;
                    $session->status = 'finished';
                    $session->save();

                    $pc->status = 'locked';
                    $pc->save();

                    PcCommand::create([
                        'tenant_id' => $pc->tenant_id,
                        'pc_id'     => $pc->id,
                        'type'      => 'LOCK',
                        'payload'   => ['reason' => 'logout'],
                        'status'    => 'pending',
                    ]);
                });
            }
        }

        if ($pc->status !== 'locked') {
            $pc->status = 'locked';
            $pc->save();
        }

        return response()->json(['ok' => true]);
    }
}
