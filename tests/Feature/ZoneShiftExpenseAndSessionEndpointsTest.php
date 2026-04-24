<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Session;
use App\Models\Shift;
use App\Models\ShiftExpense;
use App\Models\Tariff;
use App\Models\ZonePricingWindow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class ZoneShiftExpenseAndSessionEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-22 15:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_zone_catalog_store_update_filter_and_toggle_flow(): void
    {
        $fixture = $this->createTenantFixture();

        $storeResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/zones', [
                'name' => 'Standard',
                'price_per_hour' => 8000,
            ]);

        $zoneId = (int) $storeResponse->json('data.id');

        $storeResponse
            ->assertCreated()
            ->assertJsonPath('data.name', 'Standard')
            ->assertJsonPath('data.price_per_hour', 8000);

        $this->actingAsOwner($fixture['operator'])
            ->patchJson('/api/zones/' . $zoneId, [
                'price_per_hour' => 8500,
            ])
            ->assertOk()
            ->assertJsonPath('data.price_per_hour', 8500);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/zones/' . $zoneId . '/toggle')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/zones?active=0')
            ->assertOk()
            ->assertJsonPath('data.0.id', $zoneId)
            ->assertJsonPath('data.0.is_active', false);
    }

    public function test_zone_pricing_window_crud_flow(): void
    {
        $fixture = $this->createTenantFixture();

        $storeResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/zones/' . $fixture['zone']->id . '/pricing-windows', [
                'name' => 'Prime Time',
                'starts_at' => '18:00',
                'ends_at' => '02:00',
                'starts_on' => '2026-04-20',
                'ends_on' => '2026-04-30',
                'weekdays' => [5, 6, 6],
                'price_per_hour' => 12000,
            ]);

        $windowId = (int) $storeResponse->json('data.id');

        $storeResponse
            ->assertCreated()
            ->assertJsonPath('data.zone_id', $fixture['zone']->id)
            ->assertJsonPath('data.starts_at', '18:00:00')
            ->assertJsonPath('data.ends_at', '02:00:00')
            ->assertJsonPath('data.starts_on', '2026-04-20')
            ->assertJsonPath('data.ends_on', '2026-04-30')
            ->assertJsonPath('data.weekdays', [5, 6])
            ->assertJsonPath('data.price_per_hour', 12000);

        $this->actingAsOwner($fixture['operator'])
            ->patchJson('/api/zones/' . $fixture['zone']->id . '/pricing-windows/' . $windowId, [
                'name' => 'Late Prime',
                'price_per_hour' => 13000,
                'ends_on' => '2026-05-05',
                'weekdays' => [1, 2, 3],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Late Prime')
            ->assertJsonPath('data.price_per_hour', 13000)
            ->assertJsonPath('data.starts_on', '2026-04-20')
            ->assertJsonPath('data.ends_on', '2026-05-05')
            ->assertJsonPath('data.weekdays', [1, 2, 3]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/zones/' . $fixture['zone']->id . '/pricing-windows/' . $windowId . '/toggle')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/zones/' . $fixture['zone']->id . '/pricing-windows?active=0')
            ->assertOk()
            ->assertJsonPath('data.0.id', $windowId)
            ->assertJsonPath('data.0.is_active', false);

        $this->actingAsOwner($fixture['operator'])
            ->deleteJson('/api/zones/' . $fixture['zone']->id . '/pricing-windows/' . $windowId)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('zone_pricing_windows', [
            'id' => $windowId,
        ]);
    }

    public function test_current_shift_expense_flow_returns_total_and_blocks_closed_shift_delete(): void
    {
        $fixture = $this->createTenantFixture();

        $shift = Shift::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'opened_by_operator_id' => $fixture['operator']->id,
            'opened_at' => now()->subHour(),
            'opening_cash' => 100000,
            'status' => 'open',
        ]);

        $storeResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/shifts/current/expenses', [
                'amount' => 12000,
                'title' => 'Snacks',
                'category' => 'kitchen',
            ]);

        $expenseId = (int) $storeResponse->json('data.id');

        $storeResponse
            ->assertCreated()
            ->assertJsonPath('data.amount', 12000)
            ->assertJsonPath('data.operator.login', $fixture['operator']->login);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/shifts/current/expenses?limit=5')
            ->assertOk()
            ->assertJsonPath('data.shift.id', $shift->id)
            ->assertJsonPath('data.total', 12000)
            ->assertJsonPath('data.items.0.id', $expenseId);

        $this->actingAsOwner($fixture['operator'])
            ->deleteJson('/api/shifts/expenses/' . $expenseId)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $closedExpense = ShiftExpense::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'shift_id' => $shift->id,
            'operator_id' => $fixture['operator']->id,
            'amount' => 3000,
            'title' => 'Water',
            'spent_at' => now(),
        ]);

        $shift->update([
            'closed_at' => now(),
            'status' => 'closed',
            'closed_by_operator_id' => $fixture['operator']->id,
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->deleteJson('/api/shifts/expenses/' . $closedExpense->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expense']);
    }

    public function test_operator_session_start_uses_shared_tariff_and_booking_rules(): void
    {
        $fixture = $this->createTenantFixture();
        $fixture['client']->update(['balance' => 50000]);

        $tariff = $this->createTariff($fixture['tenant']->id, 'VIP');

        $otherClient = Client::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'account_id' => 'CL-OTHER',
            'login' => 'other-client',
            'password' => Hash::make('secret'),
            'balance' => 50000,
            'bonus' => 0,
            'status' => 'active',
        ]);

        Booking::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $otherClient->id,
            'created_by_operator_id' => $fixture['operator']->id,
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addMinutes(50),
            'status' => 'active',
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/sessions/start', [
                'pc_id' => $fixture['pc']->id,
                'client_id' => $fixture['client']->id,
                'tariff_id' => $tariff->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['booking']);

        Booking::query()->delete();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/sessions/start', [
                'pc_id' => $fixture['pc']->id,
                'client_id' => $fixture['client']->id,
                'tariff_id' => $tariff->id,
            ]);

        $sessionId = (int) $response->json('data.id');

        $response
            ->assertCreated()
            ->assertJsonPath('data.pc_id', $fixture['pc']->id)
            ->assertJsonPath('data.client_id', $fixture['client']->id);

        $this->assertDatabaseHas('sessions', [
            'id' => $sessionId,
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'tariff_id' => $tariff->id,
            'status' => 'active',
        ]);

        $this->assertSame('busy', (string) Session::query()->findOrFail($sessionId)->pc->fresh()->status);
    }

    public function test_session_pause_and_resume_flow_uses_projection_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-22 15:00:30'));

        $fixture = $this->createTenantFixture();
        $fixture['client']->update(['balance' => 500, 'bonus' => 0]);
        $fixture['pc']->update(['status' => 'busy']);

        $session = Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'started_at' => Carbon::parse('2026-04-22 15:00:00'),
            'status' => 'active',
            'price_total' => 0,
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/sessions/' . $session->id . '/pause')
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.paused', true)
            ->assertJsonPath('data.seconds_left', 90)
            ->assertJsonPath('data.next_charge_at', null);

        Carbon::setTestNow(Carbon::parse('2026-04-22 15:05:30'));

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/sessions/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $session->id)
            ->assertJsonPath('data.0.paused', true)
            ->assertJsonPath('data.0.seconds_left', 90);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/sessions/' . $session->id . '/resume')
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.paused', false)
            ->assertJsonPath('data.seconds_left', 90)
            ->assertJsonPath('data.started_at', Carbon::parse('2026-04-22 15:05:00')->toIso8601String())
            ->assertJsonPath('data.next_charge_at', Carbon::parse('2026-04-22 15:06:00')->toIso8601String());

        $this->assertDatabaseHas('sessions', [
            'id' => $session->id,
            'paused_at' => null,
        ]);
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
