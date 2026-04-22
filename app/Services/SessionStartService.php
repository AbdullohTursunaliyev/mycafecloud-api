<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Pc;
use App\Models\Tariff;
use Illuminate\Validation\ValidationException;

class SessionStartService
{
    public function __construct(
        private readonly ClientWalletService $wallets,
    ) {
    }

    public function resolvePc(int $tenantId, int $pcId): Pc
    {
        return Pc::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($pcId);
    }

    public function resolveClient(int $tenantId, int $clientId): Client
    {
        return Client::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($clientId);
    }

    public function resolveTariff(int $tenantId, int $tariffId): Tariff
    {
        return Tariff::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->findOrFail($tariffId);
    }

    public function ensureClientCanStart(Client $client, string $key = 'client_id'): void
    {
        if ($client->status !== 'active') {
            throw ValidationException::withMessages([
                $key => 'Клиент заблокирован',
            ]);
        }

        if ($client->expires_at && $client->expires_at->isPast()) {
            throw ValidationException::withMessages([
                $key => 'Аккаунт истёк',
            ]);
        }
    }

    public function ensureTariffMatchesPc(Pc $pc, Tariff $tariff, string $key = 'tariff_id'): void
    {
        if ($tariff->zone && $pc->zone && $tariff->zone !== $pc->zone) {
            throw ValidationException::withMessages([
                $key => 'Тариф не подходит для этой зоны',
            ]);
        }
    }

    public function ensureWalletCanStart(Client $client, Tariff $tariff, string $key = 'balance'): void
    {
        $pricePerMin = (int) ceil(((int) $tariff->price_per_hour) / 60);
        if ($this->wallets->total($client) < $pricePerMin) {
            throw ValidationException::withMessages([
                $key => 'Недостаточно средств',
            ]);
        }
    }
}
