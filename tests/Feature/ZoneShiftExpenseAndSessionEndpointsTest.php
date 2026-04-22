<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Session;
use App\Models\Shift;
use App\Models\ShiftExpense;
use App\Models\Tariff;
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
