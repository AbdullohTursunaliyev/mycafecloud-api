<?php

namespace Tests\Feature;

use App\Enums\PcCommandType;
use App\Models\ClientSubscription;
use App\Models\PcCommand;
use App\Models\Session;
use App\Models\Shift;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class ShiftAndLegacyEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-22 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_shift_open_endpoint_rejects_second_open_shift(): void
    {
        $fixture = $this->createTenantFixture();

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/shifts/open', [
                'opening_cash' => 150000,
            ])
            ->assertCreated();

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/shifts/open', [
                'opening_cash' => 200000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.shift.0', 'Смена уже открыта');
    }

    public function test_shift_unique_index_blocks_duplicate_open_shift_rows(): void
    {
        $fixture = $this->createTenantFixture();

        Shift::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'opened_by_operator_id' => $fixture['operator']->id,
            'opened_at' => now(),
            'opening_cash' => 1000,
            'status' => 'open',
        ]);

        $this->expectException(QueryException::class);

        Shift::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'opened_by_operator_id' => $fixture['operator']->id,
            'opened_at' => now()->addMinute(),
            'opening_cash' => 2000,
            'status' => 'open',
        ]);
    }

    public function test_subscription_endpoint_rejects_second_active_subscription_in_same_zone(): void
    {
        $fixture = $this->createTenantFixture();

        Shift::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'opened_by_operator_id' => $fixture['operator']->id,
            'opened_at' => now(),
            'opening_cash' => 1000,
            'status' => 'open',
        ]);

        $plan = SubscriptionPlan::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'zone_id' => $fixture['zone']->id,
            'name' => 'Monthly VIP',
            'duration_days' => 30,
            'price' => 120000,
            'is_active' => true,
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients/' . $fixture['client']->id . '/subscribe', [
                'subscription_plan_id' => $plan->id,
                'payment_method' => 'cash',
            ])
            ->assertOk();

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients/' . $fixture['client']->id . '/subscribe', [
                'subscription_plan_id' => $plan->id,
                'payment_method' => 'cash',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription_plan_id.0', 'У клиента уже есть активная подписка в этой зоне. Дождитесь окончания.');
    }

    public function test_subscription_unique_index_blocks_duplicate_active_rows(): void
    {
        $fixture = $this->createTenantFixture();

        $plan = SubscriptionPlan::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'zone_id' => $fixture['zone']->id,
            'name' => 'Monthly VIP',
            'duration_days' => 30,
            'price' => 120000,
            'is_active' => true,
        ]);

        ClientSubscription::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'subscription_plan_id' => $plan->id,
            'zone_id' => $fixture['zone']->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'payment_method' => 'cash',
            'amount' => 120000,
        ]);

        $this->expectException(QueryException::class);

        ClientSubscription::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'subscription_plan_id' => $plan->id,
            'zone_id' => $fixture['zone']->id,
            'status' => 'active',
            'starts_at' => now()->addMinute(),
            'ends_at' => now()->addDays(31),
            'payment_method' => 'cash',
            'amount' => 120000,
        ]);
    }

    public function test_legacy_shell_state_and_logout_use_shared_session_flow(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-SHELL-01');

        $fixture['client']->update(['balance' => 30000]);
        $fixture['pc']->update(['status' => 'busy']);

        $session = Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'started_at' => now()->subHour(),
            'last_billed_at' => now()->subMinutes(10),
            'price_total' => 0,
            'status' => 'active',
        ]);

        $this->postJson('/api/shell/session-state', [
            'license_key' => $license->key,
            'pc_code' => $fixture['pc']->code,
        ])
            ->assertOk()
            ->assertJsonPath('has_session', true)
            ->assertJsonPath('session.id', $session->id)
            ->assertJsonPath('pc.code', $fixture['pc']->code);

        $this->assertSame(0, (int) $session->fresh()->price_total);
        $this->assertSame(
            now()->subMinutes(10)->toIso8601String(),
            optional($session->fresh()->last_billed_at)->toIso8601String(),
        );

        $this->postJson('/api/shell/logout', [
            'license_key' => $license->key,
            'pc_code' => $fixture['pc']->code,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('sessions', [
            'id' => $session->id,
            'status' => 'finished',
        ]);
        $this->assertDatabaseHas('pcs', [
            'id' => $fixture['pc']->id,
            'status' => 'locked',
        ]);
        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Lock->value,
        ]);
    }

    public function test_legacy_pc_heartbeat_delivers_pending_command_and_logs_metrics(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-HB-01');

        $command = PcCommand::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Message->value,
            'payload' => ['text' => 'Legacy hello'],
            'status' => 'pending',
        ]);

        $this->postJson('/api/pcs/heartbeat', [
            'license_key' => $license->key,
            'pc_code' => $fixture['pc']->code,
            'metrics' => [
                'ip_address' => '10.0.0.55',
                'cpu_name' => 'Ryzen',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('command.id', $command->id)
            ->assertJsonPath('command.type', PcCommandType::Message->value)
            ->assertJsonPath('command.payload.text', 'Legacy hello');

        $this->assertDatabaseHas('pc_heartbeats', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
        ]);
        $this->assertDatabaseHas('pcs', [
            'id' => $fixture['pc']->id,
            'ip_address' => '10.0.0.55',
        ]);
        $this->assertSame('sent', (string) $command->fresh()->status);
    }
}
