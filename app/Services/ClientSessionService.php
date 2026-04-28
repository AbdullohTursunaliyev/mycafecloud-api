<?php

namespace App\Services;

use App\Enums\PcCommandType;
use App\Models\Booking;
use App\Models\Client;
use App\Models\ClientGameProfile;
use App\Models\ClientPackage;
use App\Models\Package;
use App\Models\PackageSale;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\Session;
use App\Models\Tariff;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientSessionService
{
    public function __construct(
        private readonly SessionBillingService $billing,
        private readonly PcZoneResolver $pcZoneResolver,
        private readonly ClientWalletService $wallets,
        private readonly SessionProjectionService $projection,
    ) {
    }

    public function ensureShellLoginAllowed(int $tenantId, Client $client, Pc $pc): ?ClientPackage
    {
        $matchingPackage = $this->resolveActivePackageForZone($tenantId, $client, $pc);

        if ($this->wallets->total($client) <= 0 && !$matchingPackage) {
            throw ValidationException::withMessages([
                'balance' => 'Недостаточно баланса',
            ]);
        }

        return $matchingPackage;
    }

    public function ensureShellStartAllowed(
        int $tenantId,
        Client $client,
        Pc $pc,
        string $source,
        ?int $clientPackageId = null,
    ): ?ClientPackage {
        if ($source === 'package') {
            $package = $this->resolveActivePackageForZone($tenantId, $client, $pc, $clientPackageId);
            if (!$package) {
                throw ValidationException::withMessages([
                    'package' => 'No active package is available for this zone.',
                ]);
            }

            return $package;
        }

        if ($this->wallets->total($client) <= 0) {
            throw ValidationException::withMessages([
                'balance' => 'РќРµРґРѕСЃС‚Р°С‚РѕС‡РЅРѕ Р±Р°Р»Р°РЅСЃР°',
            ]);
        }

        return null;
    }

    public function describeBillingOptions(int $tenantId, Client $client, Pc $pc): array
    {
        $pcView = $this->describePc($pc);
        $zoneName = (string) ($pcView['zone'] ?? '');
        $walletTotal = $this->wallets->total($client);
        $activePackage = $this->resolveActivePackageForZone($tenantId, $client, $pc);

        $packages = Package::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->when($zoneName !== '', fn ($query) => $query->whereRaw('lower(zone) = lower(?)', [$zoneName]))
            ->orderBy('price')
            ->get()
            ->map(fn (Package $package) => $this->packagePayload($package))
            ->values()
            ->all();

        return [
            'zone' => $zoneName,
            'rate_per_hour' => (int) ($pcView['rate_per_hour'] ?? 0),
            'balance' => [
                'available' => $walletTotal > 0,
                'amount' => $walletTotal,
            ],
            'active_package' => $activePackage ? $this->clientPackagePayload($activePackage) : null,
            'packages' => $packages,
        ];
    }

    public function purchasePackageFromClientBalance(
        int $tenantId,
        Client $client,
        Pc $pc,
        int $packageId,
    ): array {
        return DB::transaction(function () use ($tenantId, $client, $pc, $packageId) {
            $lockedClient = Client::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($client->id);

            $package = Package::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->findOrFail($packageId);

            $pcView = $this->describePc($pc);
            $zoneName = (string) ($pcView['zone'] ?? '');
            if ($zoneName !== '' && strcasecmp((string) $package->zone, $zoneName) !== 0) {
                throw ValidationException::withMessages([
                    'package_id' => 'Package is not available for this PC zone.',
                ]);
            }

            $activePackage = $this->resolveActivePackageForZone($tenantId, $lockedClient, $pc);
            if ($activePackage) {
                throw ValidationException::withMessages([
                    'package_id' => 'Client already has an active package for this zone.',
                ]);
            }

            $amount = (int) $package->price;
            if ((int) $lockedClient->balance < $amount) {
                throw ValidationException::withMessages([
                    'balance' => 'РќРµРґРѕСЃС‚Р°С‚РѕС‡РЅРѕ СЃСЂРµРґСЃС‚РІ РЅР° Р±Р°Р»Р°РЅСЃРµ РєР»РёРµРЅС‚Р°.',
                ]);
            }

            $lockedClient->balance = (int) $lockedClient->balance - $amount;
            $lockedClient->save();

            $clientPackage = ClientPackage::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $lockedClient->id,
                'package_id' => $package->id,
                'remaining_min' => (int) $package->duration_min,
                'expires_at' => null,
                'status' => 'active',
            ]);

            PackageSale::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $lockedClient->id,
                'package_id' => $package->id,
                'payment_method' => 'balance',
                'shift_id' => null,
                'operator_id' => null,
                'amount' => $amount,
                'meta' => [
                    'client_package_id' => $clientPackage->id,
                    'package_name' => $package->name,
                    'zone' => $package->zone,
                    'duration_min' => (int) $package->duration_min,
                    'source' => 'shell_gate',
                ],
            ]);

            $clientPackage->loadMissing('package');
            $lockedClient->refresh();

            return [
                'client' => $lockedClient,
                'client_package' => $clientPackage,
                'package' => $package,
                'amount' => $amount,
            ];
        });
    }

    public function startOrResumeShellSession(
        int $tenantId,
        Pc $pc,
        Client $client,
        ?ClientPackage $package = null,
        ?Carbon $now = null,
        bool $preferPackage = true,
    ): Session
    {
        $now = ($now ?: now())->copy();

        return DB::transaction(function () use ($tenantId, $pc, $client, $package, $now, $preferPackage) {
            $this->lockPc($tenantId, (int) $pc->id);

            $lockedPc = Pc::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($pc->id);

            $active = Session::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $lockedPc->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($active) {
                if ((int) $active->client_id !== (int) $client->id) {
                    throw ValidationException::withMessages([
                        'pc_code' => 'ПК уже занят другим клиентом',
                    ]);
                }

                if ($lockedPc->status !== 'busy') {
                    $lockedPc->status = 'busy';
                    $lockedPc->save();
                }

                $active->loadMissing(['pc.zoneRel', 'clientPackage.package']);
                $this->queueApplyCloudProfileCommand($tenantId, $lockedPc, $client, $active);

                return $active;
            }

            if ($preferPackage) {
                $package ??= $this->resolveActivePackageForZone($tenantId, $client, $lockedPc);
            } else {
                $package = null;
            }

            $session = Session::create([
                'tenant_id' => $tenantId,
                'pc_id' => $lockedPc->id,
                'operator_id' => null,
                'client_id' => $client->id,
                'tariff_id' => null,
                'started_at' => $now,
                'status' => 'active',
                'price_total' => 0,
                'is_package' => $package !== null,
                'client_package_id' => $package?->id,
            ]);

            if ($lockedPc->status !== 'busy') {
                $lockedPc->status = 'busy';
                $lockedPc->save();
            }

            $session->loadMissing(['pc.zoneRel', 'clientPackage.package']);
            $this->queueApplyCloudProfileCommand($tenantId, $lockedPc, $client, $session);

            return $session;
        });
    }

    public function startOperatorSession(
        int $tenantId,
        int $operatorId,
        Pc $pc,
        Client $client,
        Tariff $tariff,
        ?Booking $booking = null,
        ?Carbon $now = null,
    ): Session {
        $now = ($now ?: now())->copy();

        return DB::transaction(function () use ($tenantId, $operatorId, $pc, $client, $tariff, $booking, $now) {
            $this->lockPc($tenantId, (int) $pc->id);

            $lockedPc = Pc::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($pc->id);

            $lockedClient = Client::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($client->id);

            $active = Session::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $lockedPc->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();

            if ($active) {
                throw ValidationException::withMessages(['pc_id' => 'ПК уже занят']);
            }

            if ($booking) {
                $lockedBooking = Booking::query()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->find($booking->id);

                if ($lockedBooking && (int) $lockedBooking->client_id !== (int) $lockedClient->id) {
                    throw ValidationException::withMessages([
                        'booking' => 'ПК забронирован для другого клиента',
                    ]);
                }

                if ($lockedBooking && $lockedBooking->status === 'active') {
                    $lockedBooking->update(['status' => 'completed']);
                }
            }

            $session = Session::create([
                'tenant_id' => $tenantId,
                'pc_id' => $lockedPc->id,
                'operator_id' => $operatorId,
                'client_id' => $lockedClient->id,
                'tariff_id' => $tariff->id,
                'started_at' => $now,
                'status' => 'active',
                'price_total' => 0,
            ]);

            $lockedPc->update(['status' => 'busy']);

            $this->queueUnlockCommand($tenantId, (int) $lockedPc->id);

            return $session->loadMissing(['pc.zoneRel', 'client']);
        });
    }

    public function startAgentSession(
        int $tenantId,
        Pc $pc,
        Client $client,
        Tariff $tariff,
        ?Carbon $now = null,
    ): Session {
        $now = ($now ?: now())->copy();

        try {
            return DB::transaction(function () use ($tenantId, $pc, $client, $tariff, $now) {
                $this->lockPc($tenantId, (int) $pc->id);

                $lockedPc = Pc::query()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($pc->id);

                $lockedClient = Client::query()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($client->id);

                $active = Session::query()
                    ->where('tenant_id', $tenantId)
                    ->where('pc_id', $lockedPc->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->exists();

                if ($active) {
                    throw ValidationException::withMessages([
                        'pc' => 'ПК уже используется',
                    ]);
                }

                $session = Session::query()->create([
                    'tenant_id' => $tenantId,
                    'pc_id' => $lockedPc->id,
                    'operator_id' => null,
                    'client_id' => $lockedClient->id,
                    'tariff_id' => $tariff->id,
                    'started_at' => $now,
                    'status' => 'active',
                    'price_total' => 0,
                ]);

                $lockedPc->update(['status' => 'busy']);
                $this->queueUnlockCommand($tenantId, (int) $lockedPc->id);

                return $session->loadMissing(['pc.zoneRel', 'client']);
            });
        } catch (QueryException $exception) {
            if ($this->isActiveSessionUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'pc' => 'ПК уже используется',
                ]);
            }

            throw $exception;
        }
    }

    public function resolveOwnedActiveSession(int $tenantId, Pc $pc, Client $client): ?Session
    {
        return Session::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    public function logoutClientFromPc(int $tenantId, Pc $pc, Client $client, ?Carbon $now = null): void
    {
        $now = ($now ?: now())->copy();

        DB::transaction(function () use ($tenantId, $pc, $client, $now) {
            $this->lockPc($tenantId, (int) $pc->id);

            $lockedPc = Pc::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($pc->id);

            $session = Session::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $lockedPc->id)
                ->where('client_id', $client->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (!$session) {
                return;
            }

            $session->loadMissing(['pc.zoneRel', 'client', 'clientPackage.package']);
            $this->finalizeSession($session, $lockedPc, $client, $now, 'logout');
        });
    }

    public function logoutActiveSessionFromPc(int $tenantId, Pc $pc, ?Carbon $now = null): void
    {
        $now = ($now ?: now())->copy();

        DB::transaction(function () use ($tenantId, $pc, $now) {
            $this->lockPc($tenantId, (int) $pc->id);

            $lockedPc = Pc::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($pc->id);

            $session = Session::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $lockedPc->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (!$session) {
                $this->ensurePcLocked($tenantId, $lockedPc, 'logout');
                return;
            }

            $session->loadMissing(['pc.zoneRel', 'client', 'clientPackage.package']);
            $client = $session->client;
            $this->finalizeSession($session, $lockedPc, $client, $now, 'logout');
        });
    }

    public function stopOperatorSession(Session $session, ?Carbon $now = null): Session
    {
        $now = ($now ?: now())->copy();

        return DB::transaction(function () use ($session, $now) {
            $this->lockPc((int) $session->tenant_id, (int) $session->pc_id);

            $locked = Session::query()
                ->where('tenant_id', $session->tenant_id)
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['id' => 'Сессия уже завершена']);
            }

            $locked->loadMissing(['pc.zoneRel', 'client', 'clientPackage.package']);
            $pc = $locked->pc;
            if (!$pc) {
                throw ValidationException::withMessages(['pc_id' => 'ПК не найден']);
            }

            $client = $locked->client;
            $this->finalizeSession($locked, $pc, $client, $now, 'operator_stop');

            return $locked->fresh(['pc.zoneRel', 'client', 'clientPackage.package']);
        });
    }

    public function describePc(Pc $pc): array
    {
        $resolved = $this->pcZoneResolver->resolveNameAndRate($pc);

        return [
            'code' => $pc->code,
            'zone' => $resolved['zone_name'],
            'rate_per_hour' => $resolved['rate_per_hour'],
        ];
    }

    public function describeSession(Session $session, Client $client, Pc $pc): array
    {
        $resolved = $this->projection->describe($session, $client, $pc);

        return [
            'id' => $session->id,
            'status' => $session->status,
            'pc_id' => $pc->id,
            'pc_code' => $pc->code,
            'started_at' => $session->started_at?->toISOString(),
            'is_package' => (bool) $session->is_package,
            'client_package_id' => $session->client_package_id,
            'left_sec' => $resolved['seconds_left'],
            'seconds_left' => $resolved['seconds_left'],
            'from' => $resolved['from'],
            'zone' => $resolved['zone_name'],
            'rate_per_hour' => $resolved['rate_per_hour'],
            'next_charge_at' => $resolved['next_charge_at'],
            'paused' => $resolved['paused'],
            'pricing_rule' => $resolved['pricing_rule'],
        ];
    }

    public function resolveSessionTime(Session $session, ?Client $client, Pc $pc): array
    {
        return $this->projection->describe($session, $client, $pc);
    }

    public function resolveActivePackageForZone(
        int $tenantId,
        Client $client,
        Pc $pc,
        ?int $clientPackageId = null,
    ): ?ClientPackage
    {
        $resolved = $this->pcZoneResolver->resolveNameAndRate($pc);
        $zoneName = $resolved['zone_name'];

        $query = ClientPackage::query()
            ->with('package')
            ->where('client_packages.tenant_id', $tenantId)
            ->where('client_packages.client_id', $client->id)
            ->where('client_packages.status', 'active')
            ->where('client_packages.remaining_min', '>', 0)
            ->where(function ($q) {
                $q->whereNull('client_packages.expires_at')
                    ->orWhere('client_packages.expires_at', '>', now());
            })
            ->join('packages', 'packages.id', '=', 'client_packages.package_id')
            ->orderByDesc('client_packages.id')
            ->select('client_packages.*');

        if ($clientPackageId !== null) {
            $query->where('client_packages.id', $clientPackageId);
        }

        if ($zoneName) {
            $query->whereRaw('lower(packages.zone) = lower(?)', [$zoneName]);
        }

        return $query->first();
    }

    private function clientPackagePayload(ClientPackage $clientPackage): array
    {
        $package = $clientPackage->relationLoaded('package')
            ? $clientPackage->package
            : $clientPackage->package()->first();

        return [
            'client_package_id' => (int) $clientPackage->id,
            'package_id' => $package?->id ? (int) $package->id : null,
            'name' => $package?->name,
            'zone' => $package?->zone,
            'duration_min' => $package?->duration_min ? (int) $package->duration_min : null,
            'remaining_min' => (int) $clientPackage->remaining_min,
            'price' => $package?->price ? (int) $package->price : null,
            'expires_at' => $clientPackage->expires_at?->toIso8601String(),
            'status' => $clientPackage->status,
        ];
    }

    private function packagePayload(Package $package): array
    {
        return [
            'id' => (int) $package->id,
            'name' => (string) $package->name,
            'zone' => (string) $package->zone,
            'duration_min' => (int) $package->duration_min,
            'price' => (int) $package->price,
        ];
    }

    private function finalizeSession(Session $session, Pc $pc, ?Client $client, Carbon $now, string $reason): void
    {
        $this->billing->billForLogout($session, $now);
        $session->refresh();

        if ($client) {
            $this->queueBackupCloudProfileCommand((int) $session->tenant_id, $pc, $client, $session);
        }

        if (!$session->ended_at) {
            $session->ended_at = $now;
            $session->status = 'finished';
            $session->last_billed_at = $session->last_billed_at ?: $now;
            $session->save();
        }

        $this->ensurePcLocked((int) $session->tenant_id, $pc, $reason);
    }

    private function ensurePcLocked(int $tenantId, Pc $pc, string $reason): void
    {
        if ($pc->status !== 'locked') {
            $pc->status = 'locked';
            $pc->save();
        }

        $pendingLockExists = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('type', PcCommandType::Lock->value)
            ->whereIn('status', ['pending', 'sent'])
            ->where('created_at', '>=', now()->subMinutes((int) config('domain.pc.lock_command_recent_window_minutes', 2)))
            ->exists();

        if ($pendingLockExists) {
            return;
        }

        PcCommand::create([
            'tenant_id' => $tenantId,
            'pc_id' => $pc->id,
            'type' => PcCommandType::Lock->value,
            'payload' => ['reason' => $reason],
            'status' => 'pending',
        ]);
    }

    private function lockPc(int $tenantId, int $pcId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [$tenantId * 1000000 + $pcId]);
    }

    private function queueUnlockCommand(int $tenantId, int $pcId): void
    {
        PcCommand::create([
            'tenant_id' => $tenantId,
            'pc_id' => $pcId,
            'type' => PcCommandType::Unlock->value,
            'payload' => null,
            'status' => 'pending',
        ]);
    }

    private function isActiveSessionUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $message = $exception->getMessage();

        return $sqlState === '23505'
            && str_contains($message, 'sessions_one_active_per_pc_idx');
    }

    private function queueApplyCloudProfileCommand(int $tenantId, Pc $pc, Client $client, Session $session): void
    {
        $profiles = ClientGameProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', (int) $client->id)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['game_slug', 'version', 'mouse_json', 'archive_path', 'updated_at']);

        if ($profiles->isEmpty()) {
            return;
        }

        PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', (int) $pc->id)
            ->where('type', PcCommandType::ApplyCloudProfile->value)
            ->where('status', 'pending')
            ->delete();

        $items = $profiles->map(function ($row) {
            return [
                'game_slug' => (string) $row->game_slug,
                'version' => (int) $row->version,
                'has_archive' => !empty($row->archive_path),
                'mouse' => is_array($row->mouse_json) ? $row->mouse_json : null,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ];
        })->values()->all();

        PcCommand::create([
            'tenant_id' => $tenantId,
            'pc_id' => (int) $pc->id,
            'type' => PcCommandType::ApplyCloudProfile->value,
            'payload' => [
                'trigger' => 'client_login',
                'client_id' => (int) $client->id,
                'client_login' => (string) $client->login,
                'session_id' => (int) $session->id,
                'pc_code' => (string) $pc->code,
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
            ->where('pc_id', (int) $pc->id)
            ->where('type', PcCommandType::BackupCloudProfile->value)
            ->where('status', 'pending')
            ->delete();

        PcCommand::create([
            'tenant_id' => $tenantId,
            'pc_id' => (int) $pc->id,
            'type' => PcCommandType::BackupCloudProfile->value,
            'payload' => [
                'trigger' => 'client_logout',
                'client_id' => (int) $client->id,
                'client_login' => (string) $client->login,
                'session_id' => (int) $session->id,
                'pc_code' => (string) $pc->code,
                'capture_all_known_games' => true,
            ],
            'status' => 'pending',
        ]);
    }
}
