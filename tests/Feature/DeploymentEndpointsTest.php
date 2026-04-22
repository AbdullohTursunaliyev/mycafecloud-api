<?php

namespace Tests\Feature;

use App\Models\Pc;
use App\Models\PcPairCode;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class DeploymentEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-21 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_quick_install_creates_pair_code_and_returns_installer_contract(): void
    {
        $fixture = $this->createTenantFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/deployment/quick-install', [
                'pc_id' => $fixture['pc']->id,
                'expires_in_min' => 15,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.pc_id', $fixture['pc']->id)
            ->assertJsonPath('data.zone', 'VIP');

        $pairCode = (string) $response->json('data.pair_code');

        $this->assertNotSame('', $pairCode);
        $this->assertDatabaseHas('pc_pair_codes', [
            'tenant_id' => $fixture['tenant']->id,
            'code' => $pairCode,
            'pc_id' => $fixture['pc']->id,
        ]);
        $this->assertStringContainsString($pairCode, (string) $response->json('data.installer_script'));
        $this->assertStringContainsString(
            '/api/deployment/quick-install/' . urlencode($pairCode) . '/script.ps1',
            (string) $response->json('data.installer_script_url'),
        );
    }

    public function test_quick_install_rejects_ttl_above_domain_limit(): void
    {
        $fixture = $this->createTenantFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/deployment/quick-install', [
                'expires_in_min' => 999,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expires_in_min']);
    }

    public function test_quick_install_bulk_creates_requested_codes(): void
    {
        $fixture = $this->createTenantFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/deployment/quick-install/bulk', [
                'count' => 2,
                'zone_id' => $fixture['zone']->id,
                'expires_in_min' => 5,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.count', 2);

        $this->assertCount(2, $response->json('data.codes'));
        $this->assertSame(
            2,
            PcPairCode::query()->where('tenant_id', $fixture['tenant']->id)->count(),
        );
    }

    public function test_private_and_public_installer_script_endpoints_render_script(): void
    {
        $fixture = $this->createTenantFixture();

        $pair = PcPairCode::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'ABCD-EF',
            'zone' => 'VIP',
            'expires_at' => now()->addMinutes(10),
            'pc_id' => $fixture['pc']->id,
        ]);

        $privateResponse = $this->actingAsOwner($fixture['operator'])
            ->get('/api/deployment/quick-install/' . $pair->code . '/script.ps1/private');

        $privateResponse->assertOk();
        $privateResponse->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString($pair->code, $privateResponse->getContent());
        $this->assertStringContainsString('/agent/pair', $privateResponse->getContent());

        $publicResponse = $this->get('/api/deployment/quick-install/' . $pair->code . '/script.ps1');

        $publicResponse->assertOk();
        $publicResponse->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString($pair->code, $publicResponse->getContent());
        $this->assertStringContainsString('/agent/pair', $publicResponse->getContent());
    }

    public function test_public_installer_script_rejects_expired_pair_code(): void
    {
        $fixture = $this->createTenantFixture();

        $pair = PcPairCode::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'WXYZ-99',
            'zone' => 'VIP',
            'expires_at' => now()->subMinute(),
            'pc_id' => $fixture['pc']->id,
        ]);

        $response = $this->getJson('/api/deployment/quick-install/' . $pair->code . '/script.ps1');

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }
}
