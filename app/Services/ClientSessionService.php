<?php

namespace App\Services;

use App\Enums\PcCommandType;
use App\Models\Booking;
use App\Models\Client;
use App\Models\ClientGameProfile;
use App\Models\ClientPackage;
use App\Models\Package;
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

    public function startOrResumeShellSession(int $tenantId, Pc $pc, Client $client, ?ClientPackage $package = null, ?Carbon $now = null): Session
    {
        $now = ($now ?: now())->copy();

        return DB::transaction(function () use ($tenantId, $pc, $client, $package, $now) {
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

            $package ??= $this->resolveActivePackageForZone($tenantId, $client, $lockedPc);

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

    public function resolveActivePackageForZone(int $tenantId, Client $client, Pc $pc): ?ClientPackage
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

        if ($zoneName) {
            $query->whereRaw('lower(packages.zone) = lower(?)', [$zoneName]);
        }

        return $query->first();
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
