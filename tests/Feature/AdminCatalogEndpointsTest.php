<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PcCommandType;
use App\Models\Booking;
use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\ClientTransaction;
use App\Models\Package;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\PcHeartbeat;
use App\Models\Session;
use App\Models\Shift;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class AdminCatalogEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    public function test_logs_index_aggregates_activity_and_supports_type_filter(): void
    {
        $fixture = $this->createTenantFixture();

        ClientTransaction::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'type' => 'topup',
            'amount' => 50000,
            'bonus_amount' => 0,
            'payment_method' => PaymentMethod::Cash->value,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        PcCommand::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Lock->value,
            'payload' => [],
            'status' => 'pending',
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'started_at' => now()->subMinutes(20),
            'ended_at' => now()->subMinutes(5),
            'price_total' => 12000,
            'status' => 'finished',
        ]);

        $response = $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/logs');

        $response
            ->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.total', 4);

        $types = collect($response->json('data'))->pluck('type')->all();
        $this->assertContains('transaction', $types);
        $this->assertContains('pc_command', $types);
        $this->assertContains('session', $types);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/logs?type=pc_command')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'pc_command');
    }

    public function test_pcs_index_returns_busy_reserved_and_telemetry(): void
    {
        $fixture = $this->createTenantFixture();

        $reservedPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        PcHeartbeat::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'received_at' => now(),
            'metrics' => [
                'cpu_name' => 'Ryzen 7',
                'ram_total_mb' => 32768,
                'disks' => [['name' => 'C', 'free_gb' => 120]],
            ],
        ]);

        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'started_at' => now()->subMinutes(10),
            'status' => 'active',
            'price_total' => 0,
        ]);

        Booking::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $reservedPc->id,
            'client_id' => $fixture['client']->id,
            'created_by_operator_id' => $fixture['operator']->id,
            'start_at' => now()->subMinute(),
            'end_at' => now()->addHour(),
            'status' => 'active',
        ]);

        $response = $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/pcs');

        $response->assertOk();

        $items = collect($response->json('data'))->keyBy('code');
        $this->assertSame('busy', $items['PC-1']['status']);
        $this->assertSame('Ryzen 7', $items['PC-1']['telemetry']['cpu_name']);
        $this->assertSame('reserved', $items['PC-2']['status']);
        $this->assertNotNull($items['PC-2']['current_booking']['id']);
    }

    public function test_pc_store_and_layout_batch_update_resolve_zone_consistently(): void
    {
        $fixture = $this->createTenantFixture();
        $extraZone = Zone::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Standard',
            'price_per_hour' => 7000,
            'is_active' => true,
        ]);

        $storeResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/pcs', [
                'code' => 'PC-NEW',
                'zone' => 'Standard',
                'status' => 'online',
            ]);

        $storeResponse
            ->assertCreated()
            ->assertJsonPath('data.zone', 'Standard')
            ->assertJsonPath('data.zone_id', $extraZone->id);

        $pcId = (int) $storeResponse->json('data.id');

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/pcs/layout/batch', [
                'items' => [[
                    'id' => $pcId,
                    'pos_x' => 120,
                    'pos_y' => 340,
                    'sort_order' => 5,
                    'zone_id' => $fixture['zone']->id,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('pcs', [
            'id' => $pcId,
            'pos_x' => 120,
            'pos_y' => 340,
            'sort_order' => 5,
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
        ]);
    }

    public function test_client_store_bulk_topup_history_and_packages_flow_through_service_layer(): void
    {
        $fixture = $this->createTenantFixture();

        $storeResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients', [
                'login' => 'bulk-user',
                'name' => 'Bulk User',
                'password' => 'secret',
            ]);

        $storeResponse
            ->assertCreated()
            ->assertJsonPath('data.login', 'bulk-user')
            ->assertJsonPath('data.username', 'Bulk User');

        $secondClientId = (int) $storeResponse->json('data.id');

        Shift::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'opened_by_operator_id' => $fixture['operator']->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'status' => 'open',
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients/bulk-topup', [
                'client_ids' => [$fixture['client']->id, $secondClientId],
                'amount' => 15000,
                'payment_method' => PaymentMethod::Cash->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->assertSame(15000, (int) $fixture['client']->fresh()->balance);
        $this->assertSame(15000, (int) Client::query()->findOrFail($secondClientId)->balance);

        $historyResponse = $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/clients/' . $fixture['client']->id . '/history');

        $historyResponse
            ->assertOk()
            ->assertJsonPath('data.data.0.type', 'topup');

        $package = Package::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'VIP Pack',
            'duration_min' => 90,
            'price' => 20000,
            'zone' => 'VIP',
            'is_active' => true,
        ]);

        ClientPackage::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'package_id' => $package->id,
            'remaining_min' => 45,
            'expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/clients/' . $fixture['client']->id . '/packages')
            ->assertOk()
            ->assertJsonPath('data.0.package.name', 'VIP Pack')
            ->assertJsonPath('data.0.remaining_min', 45);
    }
}
