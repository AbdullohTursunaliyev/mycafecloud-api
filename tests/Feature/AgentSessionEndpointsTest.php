<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Session;
use App\Models\Tariff;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class AgentSessionEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-22 13:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_agent_session_start_rejects_insufficient_balance(): void
    {
        $fixture = $this->createTenantFixture();
        $token = $this->issueDeviceToken($fixture['tenant'], $fixture['pc']);
        $tariff = $this->createTariff($fixture['tenant']->id, 'VIP');

        $response = $this->withHeaders($this->deviceHeaders($token))
            ->postJson('/api/agent/sessions/start', [
                'client_id' => $fixture['client']->id,
                'tariff_id' => $tariff->id,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['balance']);
    }

    public function test_agent_session_start_rejects_tariff_zone_mismatch(): void
    {
        $fixture = $this->createTenantFixture();
        $token = $this->issueDeviceToken($fixture['tenant'], $fixture['pc']);
        $fixture['client']->update(['balance' => 50000]);
        $tariff = $this->createTariff($fixture['tenant']->id, 'Standard');

        $response = $this->withHeaders($this->deviceHeaders($token))
            ->postJson('/api/agent/sessions/start', [
                'client_id' => $fixture['client']->id,
                'tariff_id' => $tariff->id,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tariff']);
    }

    public function test_agent_session_start_rejects_when_pc_already_has_active_session(): void
    {
        $fixture = $this->createTenantFixture();
        $token = $this->issueDeviceToken($fixture['tenant'], $fixture['pc']);
        $fixture['client']->update(['balance' => 50000]);
        $tariff = $this->createTariff($fixture['tenant']->id, 'VIP');

        $otherClient = Client::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'account_id' => 'CL-2',
            'login' => 'client2',
            'password' => Hash::make('secret'),
            'balance' => 50000,
            'bonus' => 0,
            'status' => 'active',
        ]);

        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $otherClient->id,
            'tariff_id' => $tariff->id,
            'started_at' => now()->subMinutes(10),
            'status' => 'active',
            'price_total' => 0,
        ]);

        $response = $this->withHeaders($this->deviceHeaders($token))
            ->postJson('/api/agent/sessions/start', [
                'client_id' => $fixture['client']->id,
                'tariff_id' => $tariff->id,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pc']);
    }

    private function createTariff(int $tenantId, string $zone): Tariff
    {
        return Tariff::query()->create([
            'tenant_id' => $tenantId,
            'name' => $zone . ' Tariff',
            'price_per_hour' => 12000,
            'zone' => $zone,
            'is_active' => true,
        ]);
    }

    private function deviceHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
