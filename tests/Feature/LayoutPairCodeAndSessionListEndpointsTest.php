<?php

namespace Tests\Feature;

use App\Models\PcCell;
use App\Models\PcPairCode;
use App\Models\Session;
use App\Models\Setting;
use App\Models\Tariff;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class LayoutPairCodeAndSessionListEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-22 16:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_active_sessions_endpoint_uses_shared_overview_payload(): void
    {
        $fixture = $this->createTenantFixture();
        $fixture['client']->update([
            'balance' => 30000,
            'bonus' => 5000,
        ]);

        $tariff = $this->createTariff($fixture['tenant']->id, 'VIP');

        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'tariff_id' => $tariff->id,
            'started_at' => now()->subMinutes(15),
            'last_billed_at' => now()->subMinutes(1),
            'status' => 'active',
            'price_total' => 4000,
        ]);

        $response = $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/sessions/active');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.pc.code', $fixture['pc']->code)
            ->assertJsonPath('data.0.client.login', $fixture['client']->login)
            ->assertJsonPath('data.0.tariff.id', $tariff->id)
            ->assertJsonPath('data.0.from', 'balance')
            ->assertJsonPath('data.0.rate_per_hour', $tariff->price_per_hour)
            ->assertJsonPath('data.0.price_total', 4000);

        $secondsLeft = (int) $response->json('data.0.seconds_left');
        $this->assertGreaterThan(0, $secondsLeft);
    }

    public function test_layout_index_grid_update_and_cell_batch_flow_use_service_layer(): void
    {
        $fixture = $this->createTenantFixture();
        $extraZone = Zone::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Standard',
            'price_per_hour' => 7000,
            'is_active' => true,
        ]);

        $cell = PcCell::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'row' => 1,
            'col' => 1,
            'zone_id' => $fixture['zone']->id,
            'pc_id' => $fixture['pc']->id,
            'label' => 'VIP-1',
            'is_active' => true,
            'notes' => 'Front row',
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/layout')
            ->assertOk()
            ->assertJsonPath('grid.rows', 8)
            ->assertJsonPath('grid.cols', 12)
            ->assertJsonPath('data.0.id', $cell->id)
            ->assertJsonPath('data.0.pc.code', $fixture['pc']->code);

        $this->actingAsOwner($fixture['operator'])
            ->patchJson('/api/layout/grid', [
                'rows' => 10,
                'cols' => 14,
            ])
            ->assertOk()
            ->assertJsonPath('grid.rows', 10)
            ->assertJsonPath('grid.cols', 14);

        $this->assertEquals(['rows' => 10, 'cols' => 14], Setting::query()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('key', 'layout.grid')
            ->value('value'));

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/layout/cells/batch', [
                'items' => [[
                    'id' => $cell->id,
                    'row' => 2,
                    'col' => 3,
                    'zone_id' => $extraZone->id,
                    'pc_id' => $fixture['pc']->id,
                    'label' => 'STD-1',
                    'notes' => 'Moved',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('pc_cells', [
            'id' => $cell->id,
            'row' => 2,
            'col' => 3,
            'zone_id' => $extraZone->id,
            'pc_id' => $fixture['pc']->id,
            'label' => 'STD-1',
            'notes' => 'Moved',
        ]);
        $this->assertDatabaseHas('pcs', [
            'id' => $fixture['pc']->id,
            'zone_id' => $extraZone->id,
            'zone' => 'Standard',
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/layout/cells/batch', [
                'items' => [[
                    'id' => $cell->id,
                    'row' => 2,
                    'col' => 3,
                    'is_active' => false,
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('pc_cells', [
            'id' => $cell->id,
            'is_active' => false,
            'pc_id' => null,
        ]);
    }

    public function test_pc_pair_code_create_uses_config_aware_service(): void
    {
        $fixture = $this->createTenantFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/pcs/pair-code', [
                'zone' => 'VIP',
                'expires_in_min' => 15,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.zone', 'VIP');

        $code = (string) $response->json('data.code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{2}$/', $code);

        $pair = PcPairCode::query()->where('code', $code)->firstOrFail();
        $this->assertSame($fixture['tenant']->id, (int) $pair->tenant_id);
        $this->assertSame('VIP', (string) $pair->zone);
        $this->assertSame(
            now()->addMinutes(15)->toIso8601String(),
            $pair->expires_at?->toIso8601String()
        );
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
}
