<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientGameProfile;
use App\Models\ClientToken;
use App\Models\LicenseKey;
use App\Models\Pc;
use App\Models\Session;
use App\Models\ClientPackage;
use App\Models\PcCommand;
use App\Models\Zone;
use App\Models\PcCell;
use App\Service\SettingService;
use App\Services\SessionBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class ClientAuthController extends Controller
{
    private const DELIVERY_TYPES = [
        'LOCK',
        'UNLOCK',
        'REBOOT',
        'SHUTDOWN',
        'MESSAGE',
        'INSTALL_GAME',
        'UPDATE_GAME',
        'ROLLBACK_GAME',
        'UPDATE_SHELL',
        'RUN_SCRIPT',
        'APPLY_CLOUD_PROFILE',
        'BACKUP_CLOUD_PROFILE',
    ];

    public function login(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required','string'],
            'pc_code'     => ['required','string'],   // вњ… MUHIM
            'account_id'  => ['nullable','string'],
            'login'       => ['nullable','string'],
            'password'    => ['nullable','string'],
        ]);

        $license = LicenseKey::with('tenant')
            ->where('key', $data['license_key'])
            ->first();

        if (!$license || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast())) {
            throw ValidationException::withMessages(['license_key' => 'Р›РёС†РµРЅР·РёСЏ РЅРµРґРµР№СЃС‚РІРёС‚РµР»СЊРЅР°']);
        }
        if ($license->tenant?->status !== 'active') {
            throw ValidationException::withMessages(['license_key' => 'РљР»СѓР± Р·Р°Р±Р»РѕРєРёСЂРѕРІР°РЅ']);
        }

        $tenantId = $license->tenant_id;

        // вњ… PC topamiz: pcs.code
        $pc = Pc::with('zoneRel')
            ->where('tenant_id', $tenantId)
            ->where('code', $data['pc_code'])
            ->first();

        if (!$pc) {
            throw ValidationException::withMessages(['pc_code' => 'РџРљ РЅРµ РЅР°Р№РґРµРЅ РїРѕ РєРѕРґСѓ']);
        }

        // client topish
        $client = null;

        if (!empty($data['account_id'])) {
            $client = Client::where('tenant_id',$tenantId)
                ->where('account_id', $data['account_id'])
                ->first();
            if (!$client) {
                throw ValidationException::withMessages(['account_id' => 'РђРєРєР°СѓРЅС‚ РЅРµ РЅР°Р№РґРµРЅ']);
            }
        } else {
            if (empty($data['login']) || empty($data['password'])) {
                throw ValidationException::withMessages(['login' => 'Р’РІРµРґРёС‚Рµ login Рё РїР°СЂРѕР»СЊ']);
            }

            $identifier = trim((string)$data['login']);
            $client = Client::where('tenant_id',$tenantId)
                ->where(function ($q) use ($identifier) {
                    $q->where('login', $identifier)
                        ->orWhere('account_id', $identifier)
                        ->orWhere('phone', $identifier);
                })
                ->orderByRaw(
                    "CASE
                        WHEN login = ? THEN 0
                        WHEN account_id = ? THEN 1
                        WHEN phone = ? THEN 2
                        ELSE 3
                    END",
                    [$identifier, $identifier, $identifier]
                )
                ->first();

            if (!$client || !$client->password || !Hash::check($data['password'], $client->password)) {
                throw ValidationException::withMessages(['login' => 'РќРµРІРµСЂРЅС‹Р№ Р»РѕРіРёРЅ РёР»Рё РїР°СЂРѕР»СЊ']);
            }
        }

        if ($client->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'РђРєРєР°СѓРЅС‚ Р·Р°Р±Р»РѕРєРёСЂРѕРІР°РЅ']);
        }
        if ($client->expires_at && $client->expires_at->isPast()) {
            throw ValidationException::withMessages(['expires_at' => 'РђРєРєР°СѓРЅС‚ РёСЃС‚С‘Рє']);
        }

        // вњ… PC ni oldin topib oling (zone tekshirish uchun)
        $pc = \App\Models\Pc::with('zoneRel')
            ->where('tenant_id', $tenantId)
            ->where('code', $data['pc_code'])
            ->first();

        if (!$pc) {
            return response()->json(['message' => 'PC not found'], 404);
        }

        // вњ… pc zone aniqlash (string)
        [$pcZone] = $this->resolveZoneAndRate($pc);

        $balance = (int)($client->balance ?? 0);
        $bonus   = (int)($client->bonus ?? 0);

        // вњ… package: client_packages + packages.zone (string) match
        $hasPackageForZone = false;

        if ($pcZone) {
            $hasPackageForZone = \App\Models\ClientPackage::query()
                ->where('client_packages.tenant_id', $tenantId)
                ->where('client_packages.client_id', $client->id)
                ->where('client_packages.status', 'active')
                ->where('client_packages.remaining_min', '>', 0)
                ->join('packages', 'packages.id', '=', 'client_packages.package_id')
                ->whereRaw('lower(packages.zone) = lower(?)', [$pcZone])
                ->exists();
        }

        // вњ… QOIDA: balance=0, bonus=0, package(zone) yoвЂq => login yoвЂq
        if ($balance <= 0 && $bonus <= 0 && !$hasPackageForZone) {
            return response()->json([
                'message' => 'РќРµРґРѕСЃС‚Р°С‚РѕС‡РЅРѕ Р±Р°Р»Р°РЅСЃ',
                'code'    => 'INSUFFICIENT_BALANCE'
            ], 403);
        }


        // вњ… token
        $plain = Str::random(48);
        ClientToken::create([
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(12),
            'last_used_at' => now(),
        ]);

        // вњ… Agar shu PCвЂ™da active session boвЂlsa вЂ” existing session qaytaramiz
        $active = Session::where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('status', 'active')
            ->first();

        if ($active) {
            if ($pc->status !== 'busy') {
                $pc->status = 'busy';
                $pc->save();
            }
            $this->queueApplyCloudProfileCommand($tenantId, $pc, $client, $active);
            return response()->json([
                'token' => $plain,
                'client' => $this->clientPayload($client, $pc),
                'pc' => $this->pcPayload($pc),
                'session' => $this->sessionPayload($active, $pc, $client),
                'note' => 'existing_active_session'
            ]);
        }

        // вњ… package bor-yoвЂqligini tekshirish
        $cp = ClientPackage::where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->where(function($q){
                $q->whereNull('expires_at')->orWhere('expires_at','>', now());
            })
            ->orderBy('id','desc')
            ->first();

        $isPackage = false;
        $clientPackageId = null;

        if ($cp && (int)$cp->remaining_min > 0) {
            $isPackage = true;
            $clientPackageId = $cp->id;
        }

        // вњ… sessiya yaratamiz
        $session = new Session();
        $session->tenant_id = $tenantId;
        $session->pc_id = $pc->id;
        $session->client_id = $client->id;
        $session->started_at = now();
        $session->status = 'active';
        $session->price_total = 0;

        $session->operator_id = null;
        $session->tariff_id = null;

        $session->is_package = $isPackage;
        $session->client_package_id = $clientPackageId;

        $session->save();
        if ($pc->status !== 'busy') {
            $pc->status = 'busy';
            $pc->save();
        }
        $this->queueApplyCloudProfileCommand($tenantId, $pc, $client, $session);

        return response()->json([
            'token' => $plain,
            'client' => $this->clientPayload($client, $pc),
            'pc' => $this->pcPayload($pc),
            'session' => $this->sessionPayload($session, $pc, $client),
        ]);
    }

    public function publicSettings(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string'],
            'pc_code' => ['required', 'string'],
        ]);

        $license = LicenseKey::with('tenant')
            ->where('key', $data['license_key'])
            ->first();

        if (!$license || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast())) {
            return response()->json(['message' => 'License invalid'], 403);
        }
        if ($license->tenant?->status !== 'active') {
            return response()->json(['message' => 'Tenant blocked'], 403);
        }

        $tenantId = $license->tenant_id;

        $pc = Pc::where('tenant_id', $tenantId)
            ->where('code', $data['pc_code'])
            ->first();

        if (!$pc) {
            return response()->json(['message' => 'PC not found'], 404);
        }

        $promoUrl = SettingService::get($tenantId, 'promo_video_url', null);
        $promoUrl = $this->normalizePromoUrl($promoUrl, $request);
        if (is_string($promoUrl)) {
            SettingService::set($tenantId, 'promo_video_url', $promoUrl);
        }

        return response()->json([
            'ok' => true,
            'settings' => [
                'promo_video_url' => $promoUrl,
            ],
        ]);
    }

    private function normalizePromoUrl(mixed $value, Request $request): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $url = trim($value);
        if ($url === '') {
            return $value;
        }

        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        $fixed = preg_replace('#^https?://(localhost|127\\.0\\.0\\.1)(:\\d+)?#i', $base, $url);
        return $fixed ?: $url;
    }

    private function clientPayload(Client $client, Pc $pc): array
    {
        return [
            'id' => $client->id,
            'account_id' => $client->account_id,
            'login' => $client->login,
            'phone' => $client->phone ?? null,
            'username' => $client->username ?? null,
            'balance' => $client->balance,
            'bonus' => $client->bonus,
            'pc' => $pc->code, // shell UI: "PC"
        ];
    }

    private function resolveZoneAndRate(Pc $pc): array
    {
        // 1) pcs.zone_id -> zoneRel
        $zone = $pc->relationLoaded('zoneRel') ? $pc->zoneRel : null;

        // 2) fallback: pcs.zone_id -> zones.id
        if (!$zone && !empty($pc->zone_id)) {
            $zone = Zone::where('tenant_id', $pc->tenant_id)
                ->where('id', (int)$pc->zone_id)
                ->first();
        }

        // 3) fallback: pcs.zone string -> zones.name
        if (!$zone && !empty($pc->zone)) {
            $zone = Zone::where('tenant_id', $pc->tenant_id)
                ->whereRaw('lower(name) = lower(?)', [$pc->zone])
                ->first();
        }

        // 4) fallback: layout cell zone (pc_cells.zone_id)
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

        $zoneName = $zone?->name ?? ($pc->zone ?: null);
        $ratePerHour = $zone ? (int)$zone->price_per_hour : 0;

        return [$zoneName, $ratePerHour];
    }

    private function pcPayload(Pc $pc): array
    {
        [$zoneName, $ratePerHour] = $this->resolveZoneAndRate($pc);

        return [
            'code' => $pc->code,
            'zone' => $zoneName,
            'rate_per_hour' => $ratePerHour,
        ];
    }

    private function sessionPayload(Session $s, Pc $pc, Client $client): array
    {
        // left seconds:
        // package boвЂlsa remaining_min *60
        // package boвЂlmasa: client balance / zone rate_per_hour
        [$zoneName, $ratePerHour] = $this->resolveZoneAndRate($pc);
        [$leftSec, $from] = $this->computeSessionTime($s, $client, $ratePerHour);

        return [
            'id' => $s->id,
            'status' => $s->status,
            'pc_id' => $pc->id,
            'pc_code' => $pc->code,
            'started_at' => $s->started_at?->toISOString(),
            'is_package' => (bool)$s->is_package,
            'client_package_id' => $s->client_package_id,

            // вњ… BACKWARD COMPAT (old field)
            'left_sec' => $leftSec,

            // вњ… NEW (shell expects this)
            'seconds_left' => $leftSec,
            'from' => $from,
            'zone' => $zoneName,
            'rate_per_hour' => $ratePerHour,
        ];
    }

    private function computeSessionTime(Session $session, Client $client, int $ratePerHour): array
    {
        if ((bool) $session->is_package && $session->client_package_id) {
            $cp = ClientPackage::query()->whereKey($session->client_package_id)->first();
            if ($cp && (int)$cp->remaining_min > 0) {
                $raw = max(0, (int)$cp->remaining_min) * 60;
                $left = $this->subtractElapsedSeconds($session, $raw);
                if ($left > 0) {
                    return [$left, 'package'];
                }
            }
        }

        $balance = max(0, (int)($client->balance ?? 0));
        $bonus = max(0, (int)($client->bonus ?? 0));
        $from = $balance > 0 ? 'balance' : ($bonus > 0 ? 'bonus' : 'balance');

        if ($ratePerHour <= 0) {
            return [0, $from];
        }

        $wallet = $balance + $bonus;
        $raw = (int) floor(($wallet / $ratePerHour) * 3600);
        $left = $this->subtractElapsedSeconds($session, $raw);

        return [$left, $from];
    }

    private function subtractElapsedSeconds(Session $session, int $rawSeconds): int
    {
        $rawSeconds = max(0, (int)$rawSeconds);
        if ($rawSeconds <= 0) return 0;

        $anchor = $session->last_billed_at ?: $session->started_at;
        if (!$anchor) return $rawSeconds;

        $elapsed = (int)$anchor->diffInSeconds(now(), false);
        if ($elapsed < 0) $elapsed = 0;
        return max(0, $rawSeconds - $elapsed);
    }

    private function walletTotal(Client $client): int
    {
        $balance = max(0, (int)($client->balance ?? 0));
        $bonus = max(0, (int)($client->bonus ?? 0));
        return $balance + $bonus;
    }

    private function chargeWallet(Client $client, int $amount): int
    {
        $need = max(0, (int)$amount);
        if ($need <= 0) return 0;

        $balance = max(0, (int)($client->balance ?? 0));
        $bonus = max(0, (int)($client->bonus ?? 0));
        $available = $balance + $bonus;
        if ($available <= 0) return 0;

        $charge = min($need, $available);
        $fromBalance = min($balance, $charge);
        $fromBonus = $charge - $fromBalance;

        $client->balance = $balance - $fromBalance;
        $client->bonus = $bonus - $fromBonus;
        $client->save();

        return $charge;
    }

    public function state(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required','string'],
            'pc_code'     => ['required','string'],
        ]);

        $license = LicenseKey::with('tenant')
            ->where('key', $data['license_key'])
            ->first();

        if (!$license || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast())) {
            return response()->json(['message'=>'License invalid'], 403);
        }
        if ($license->tenant?->status !== 'active') {
            return response()->json(['message'=>'Tenant blocked'], 403);
        }

        $tenantId = $license->tenant_id;

        // token auth: Authorization: Bearer <token>
        $bearer = $request->bearerToken();
        if (!$bearer) return response()->json(['message'=>'No token'], 401);

        $tokenHash = hash('sha256', $bearer);
        $tok = ClientToken::where('tenant_id',$tenantId)
            ->where('token_hash',$tokenHash)
            ->where('expires_at','>',now())
            ->first();

        if (!$tok) return response()->json(['message'=>'Token invalid'], 401);

        $client = Client::where('tenant_id',$tenantId)->find($tok->client_id);
        if (!$client) return response()->json(['message'=>'Client not found'], 404);

        $pc = Pc::with('zoneRel')
            ->where('tenant_id',$tenantId)
            ->where('code', $data['pc_code'])
            ->first();

        if (!$pc) return response()->json(['message'=>'PC not found'], 404);

        $session = Session::where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        [$zoneName, $ratePerHour] = $this->resolveZoneAndRate($pc);

        if ($session) {
            try {
                app(SessionBillingService::class)->billSingleSession($session);
                $session->refresh();
                $client->refresh();
            } catch (\Throwable $e) {
                // billing fallback: ignore errors, state response still returns
            }
        }

        $secondsLeft = 0;
        $from = 'balance';
        if ($session) {
            [$secondsLeft, $from] = $this->computeSessionTime($session, $client, $ratePerHour);
        }

        $commandPayload = null;
        $cmdQuery = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('status', 'pending')
            ->whereIn('type', self::DELIVERY_TYPES)
            ->orderBy('id');

        if ($session && $session->started_at) {
            // Eski sessiyadan qolib ketgan LOCK commandlar yangi sessiyani uzib yubormasin.
            PcCommand::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $pc->id)
                ->where('status', 'pending')
                ->where('type', 'LOCK')
                ->where('created_at', '<', $session->started_at)
                ->update([
                    'status' => 'failed',
                    'ack_at' => now(),
                    'error' => 'stale_lock_ignored_active_session',
                ]);

            $cmdQuery->where(function ($q) use ($session) {
                $q->where('type', '!=', 'LOCK')
                    ->orWhere('created_at', '>=', $session->started_at);
            });
        }

        $cmd = $cmdQuery->first();

        if ($cmd) {
            $cmd->status = 'sent';
            $cmd->sent_at = now();
            $cmd->save();

            $commandPayload = [
                'id' => $cmd->id,
                'type' => $cmd->type,
                'payload' => $cmd->payload,
            ];
        }

        return response()->json([
            'locked' => !$session,
            'client' => [
                'id' => $client->id,
                'login' => $client->login,
                'balance' => $client->balance,
                'bonus' => $client->bonus,
                'pc' => $pc->code,
            ],
            'pc' => [
                'code' => $pc->code,
                'zone' => $zoneName,
                'rate_per_hour' => $ratePerHour,
            ],
            'session' => [
                'id' => $session?->id,
                'status' => $session?->status,
                'started_at' => $session?->started_at,
                'seconds_left' => $secondsLeft,
                'from' => $from,
            ],
            'command' => $commandPayload,
        ]);
    }

    public function logout(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required','string'],
            'pc_code'     => ['required','string'],
        ]);

        $license = \App\Models\LicenseKey::with('tenant')
            ->where('key', $data['license_key'])
            ->first();

        if (!$license || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast())) {
            return response()->json(['message' => 'License invalid'], 403);
        }
        if ($license->tenant?->status !== 'active') {
            return response()->json(['message' => 'Tenant blocked'], 403);
        }

        $tenantId = $license->tenant_id;

        $bearer = $request->bearerToken();
        if (!$bearer) return response()->json(['message'=>'No token'], 401);

        $tok = \App\Models\ClientToken::where('tenant_id',$tenantId)
            ->where('token_hash', hash('sha256', $bearer))
            ->first();

        if (!$tok) return response()->json(['message'=>'Token invalid'], 401);

        $pc = \App\Models\Pc::with('zoneRel')->where('tenant_id',$tenantId)
            ->where('code', $data['pc_code'])
            ->first();

        if ($pc) {
            // вњ… active session: ended_at NULL boвЂlsa active deb olamiz
            $session = \App\Models\Session::where('tenant_id',$tenantId)
                ->where('pc_id', $pc->id)
                ->whereNull('ended_at')
                ->latest('id')
                ->first();

            if ($session) {
                \Illuminate\Support\Facades\DB::transaction(function () use ($session, $pc) {

                    $session->refresh();
                    if ($session->ended_at) return;

                    $now = now();

                    // ====== FINAL BILLING (logout paytida) ======
                    $last = $session->last_billed_at ?? $session->started_at ?? $now;
                    $mins = (int) ceil($last->diffInSeconds($now) / 60); // вњ… logoutda CEIL qilamiz (vaqt ketgan boвЂlsa albatta yechilsin)
                    if ($mins < 0) $mins = 0;

                    // zone rate
                    $zone = $pc->zoneRel ?? null;
                    if (!$zone && !empty($pc->zone)) {
                        $zone = \App\Models\Zone::where('tenant_id', $pc->tenant_id)
                            ->where('name', $pc->zone)
                            ->first();
                    }
                    $pricePerHour = (int)($zone?->price_per_hour ?? 0);
                    $pricePerMin = $pricePerHour > 0 ? (int) ceil($pricePerHour / 60) : 0;

                    $client = \App\Models\Client::where('id', $session->client_id)->lockForUpdate()->first();

                    if ($mins > 0 && $client && $pricePerMin > 0) {
                        // PACKAGE boвЂlsa remaining_min kamaytir
                        if (!empty($session->is_package) && !empty($session->client_package_id)) {
                            $cp = \App\Models\ClientPackage::where('id', $session->client_package_id)->lockForUpdate()->first();
                            if ($cp && (int)$cp->remaining_min > 0) {
                                $can = min($mins, (int)$cp->remaining_min);
                                $cp->remaining_min = (int)$cp->remaining_min - $can;
                                if ((int)$cp->remaining_min <= 0) {
                                    $cp->remaining_min = 0;
                                    $cp->status = 'finished';
                                }
                                $cp->save();
                            } else {
                                // package yoвЂq boвЂlsa balanceвЂ™dan yechamiz
                                $need = $mins * $pricePerMin;
                                $charged = $this->chargeWallet($client, $need);
                                $session->price_total = (int)($session->price_total ?? 0) + $charged;
                            }
                        } else {
                            $need = $mins * $pricePerMin;
                            $charged = $this->chargeWallet($client, $need);
                            $session->price_total = (int)($session->price_total ?? 0) + $charged;
                        }
                    }

                    $session->last_billed_at = $now;

                    // ====== finish session ======
                    $session->ended_at = $now;
                    $session->status   = 'finished';
                    $session->save();

                    if ($client) {
                        $this->queueBackupCloudProfileCommand($tenantId, $pc, $client, $session);
                    }

                    // lock pc
                    $pc->status = 'locked';
                    $pc->save();

                    \App\Models\PcCommand::create([
                        'tenant_id' => $pc->tenant_id,
                        'pc_id'     => $pc->id,
                        'type'      => 'LOCK',
                        'payload'   => ['reason' => 'logout'],
                        'status'    => 'pending',
                    ]);
                });
            } else {
                // session topilmasa ham pc ni lock qilamiz
                $pc->status = 'locked';
                $pc->save();
            }
        }

        $tok->delete();

        return response()->json(['ok' => true]);
    }
    private function queueApplyCloudProfileCommand(int $tenantId, Pc $pc, Client $client, Session $session): void
    {
        $profiles = ClientGameProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', (int)$client->id)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['game_slug', 'version', 'mouse_json', 'archive_path', 'updated_at']);

        if ($profiles->isEmpty()) {
            return;
        }

        PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', (int)$pc->id)
            ->where('type', 'APPLY_CLOUD_PROFILE')
            ->where('status', 'pending')
            ->delete();

        $items = $profiles->map(function ($row) {
            return [
                'game_slug' => (string)$row->game_slug,
                'version' => (int)$row->version,
                'has_archive' => !empty($row->archive_path),
                'mouse' => is_array($row->mouse_json) ? $row->mouse_json : null,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ];
        })->values()->all();

        PcCommand::create([
            'tenant_id' => $tenantId,
            'pc_id' => (int)$pc->id,
            'type' => 'APPLY_CLOUD_PROFILE',
            'payload' => [
                'trigger' => 'client_login',
                'client_id' => (int)$client->id,
                'client_login' => (string)$client->login,
                'session_id' => (int)$session->id,
                'pc_code' => (string)$pc->code,
                'profiles' => $items,
                'mouse_vendor_priority' => ['logitech', 'razer', 'generic'],
            ],
            'status' => 'pending',
        ]);
    }

    private function queueBackupCloudProfileCommand(int $tenantId, Pc $pc, Client $client, Session $session): void
    {
        PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', (int)$pc->id)
            ->where('type', 'BACKUP_CLOUD_PROFILE')
            ->where('status', 'pending')
            ->delete();

        PcCommand::create([
            'tenant_id' => $tenantId,
            'pc_id' => (int)$pc->id,
            'type' => 'BACKUP_CLOUD_PROFILE',
            'payload' => [
                'trigger' => 'client_logout',
                'client_id' => (int)$client->id,
                'client_login' => (string)$client->login,
                'session_id' => (int)$session->id,
                'pc_code' => (string)$pc->code,
                'capture_all_known_games' => true,
            ],
            'status' => 'pending',
        ]);
    }
}

