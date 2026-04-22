<?php

namespace Tests\Feature\Concerns;

use App\Models\Client;
use App\Models\LicenseKey;
use App\Models\Operator;
use App\Models\Pc;
use App\Models\Tenant;
use App\Models\Zone;
use App\Services\ClientTokenService;
use App\Services\PcDeviceTokenService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

trait CreatesTenantApiFixtures
{
    protected function createTenantFixture(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Test Club',
            'status' => 'active',
        ]);

        $operator = Operator::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Owner',
            'login' => 'owner',
            'password' => Hash::make('secret'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $zone = Zone::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'VIP',
            'price_per_hour' => 10000,
            'is_active' => true,
        ]);

        $pc = Pc::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'PC-1',
            'zone_id' => $zone->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $client = Client::query()->create([
            'tenant_id' => $tenant->id,
            'account_id' => 'CL-1',
            'login' => 'client1',
            'password' => Hash::make('secret'),
            'balance' => 0,
            'bonus' => 0,
            'status' => 'active',
        ]);

        return compact('tenant', 'operator', 'zone', 'pc', 'client');
    }

    protected function actingAsOwner(Operator $operator): static
    {
        Sanctum::actingAs($operator, ['*'], 'operator');

        return $this;
    }

    protected function issueClientToken(Tenant|int $tenant, Client|int $client): string
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : (int) $tenant;
        $clientId = $client instanceof Client ? (int) $client->id : (int) $client;

        return (string) app(ClientTokenService::class)->issue($tenantId, $clientId)['plain'];
    }

    protected function issueDeviceToken(Tenant|int $tenant, Pc|int $pc, ?Carbon $expiresAt = null): string
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : (int) $tenant;
        $pcId = $pc instanceof Pc ? (int) $pc->id : (int) $pc;

        return (string) app(PcDeviceTokenService::class)->issue($tenantId, $pcId, $expiresAt)['plain'];
    }

    protected function createLicenseKey(Tenant|int $tenant, ?string $key = null): LicenseKey
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : (int) $tenant;

        return LicenseKey::query()->create([
            'tenant_id' => $tenantId,
            'key' => $key ?? 'LIC-' . strtoupper(substr(hash('sha1', (string) $tenantId . microtime()), 0, 12)),
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);
    }
}
